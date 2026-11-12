<?php

namespace Z77\Module\Receipt\Api;

use Z77\Core\DI;
use Z77\Module\Receipt\Services\InvalidReceiptException;
use Z77\Module\Receipt\Services\ReceiptCaptureService;
use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;
use Z77\Shared\Api\ScopedApiServiceInterface;

/**
 * `POST /api/v1/receipts` — a phone captures a receipt into the receipt journal (E1): multipart,
 * the photo as file field `photo`, the values as form fields
 *
 *   amount          the gross amount as typed («88.10», «1'200,00») — required (E5)
 *   date            `YYYY-MM-DD`, empty = today (E6)
 *   text            free text, empty = the template's label
 *   template        a receipt template's code, empty = «Ohne Vorlage» (E2)
 *   payment_method  a payment method's code («Bezahlt mit», E10), empty = none
 *
 * Answers 200 with the journal position — never a ledger entry number, the ledger sees the receipt
 * only at «Journal verbuchen» (E1):
 *
 *   {"receipt": {"id": 17, "date": "2026-11-12", "amount": "88.10", "text": "Tanken",
 *                "template": "tanken", "payment_method": "kasse", "state": "captured"},
 *    "message": "Beleg erfasst"}
 *
 * or 422 `invalid_fields` with the capture's German message per field in `error.fields` (keys as
 * {@see InvalidReceiptException}: `amount`, `date`, `text`, `template`, `payment_method`,
 * `photo`, …). Nothing is stored then.
 *
 * Who captured is DATA from the device principal (T1, E8): the owner's name, the device id and its
 * label. The request's `Idempotency-Key` becomes the receipt's capture key: the gateway replays a
 * resend within 24 h, the capture key keeps a later resend of the same queued receipt from
 * becoming a second one (E7). The photo's checks (sniff, allowlist, size) are the capture
 * service's — this target only reports an upload that never arrived.
 */
final class ReceiptsService implements ScopedApiServiceInterface
{
    use DeviceOnlyTarget;

    /** The fields a phone sends besides the photo — anything else in the body is ignored. */
    public const FIELDS = ['amount', 'date', 'text', 'template', 'payment_method'];

    public function scopeFor(string $method, array $slugs): ?string
    {
        return $method === 'post' ? self::SCOPE : null;
    }

    public function handle(ApiRequest $request): ApiResult
    {
        $refused = self::refused($request);
        if ($refused !== null) {
            return $refused;
        }
        $device = $request->device();

        $uploadError = [];
        $photo       = $request->file('photo');
        $bytes       = '';
        if ($photo !== null) {
            if ($photo->isOk()) {
                $bytes = $photo->bytes() ?? '';
            } else {
                $uploadError['photo'] = in_array($photo->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'Das Foto ist zu gross für den Server.'
                    : 'Das Foto ist nicht vollständig angekommen. Bitte nochmals senden.';
            }
        }

        $value = static function (string $field) use ($request): string {
            $raw = $request->body[$field] ?? '';
            return is_string($raw) || is_int($raw) || is_float($raw) ? trim((string) $raw) : '';
        };

        try {
            $receipt = (new ReceiptCaptureService(DI::getUnifiedEntityManager()))->capture(
                amount:            $value('amount'),
                photoBytes:        $bytes,
                photoName:         $photo?->originalName ?? '',
                capturedBy:        (string) $device->ownerName,
                date:              $value('date') !== '' ? $value('date') : null,
                text:              $value('text'),
                templateCode:      $value('template') !== '' ? $value('template') : null,
                paymentMethodCode: $value('payment_method') !== '' ? $value('payment_method') : null,
                deviceId:          $device->deviceId,
                deviceName:        $device->deviceLabel,
                captureKey:        $request->idempotencyKey,
            );
        } catch (InvalidReceiptException $e) {
            // The upload's own message wins over «Das Foto fehlt.» — it says why.
            return ApiResult::invalidFields($uploadError + $e->errors, 'Receipt refused, see fields.');
        }

        return ApiResult::payload([
            'receipt' => [
                'id'             => $receipt->getId(),
                'date'           => $receipt->getReceiptDate()->format('Y-m-d'),
                'amount'         => $receipt->getAmount()->toDecimal(),
                'text'           => $receipt->getText(),
                'template'       => $receipt->getTemplateCode(),
                'payment_method' => $receipt->getPaymentMethodCode(),
                'state'          => $receipt->getState()->value,
            ],
            'message' => 'Beleg erfasst',
        ]);
    }
}
