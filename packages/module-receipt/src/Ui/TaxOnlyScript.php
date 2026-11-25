<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Core\Http\Response\HtmlResponse;

/**
 * The small script of the receipt window and the template window that locks «Soll» while «nur
 * Steuer» is chosen (`res/assets/js/backend/tax-only.js`; VAT return stage S5, owner decision S56-2)
 * — DISPLAY ONLY: the server clears a typed Soll and derives the account from the code. Fetched (a
 * window) the answer asks core.js to load it (`load-script`), as a page it goes into the footer.
 * Without it the windows still work: the field stays editable and the server ignores it.
 *
 * The using class MUST provide `$layoutManager`; a harness host overrides {@see receiptTaxOnlyScript()}.
 */
trait TaxOnlyScript
{
    private function receiptTaxOnlyScript(HtmlResponse $response, bool $window): void
    {
        try {
            if ($window) {
                $response->addCommand('load-script', ['src' => $this->layoutManager->resolveJsPath('backend/tax-only', 'Z77\\Module\\Receipt')]);
            } else {
                $this->layoutManager->addJs('backend/tax-only', 'Z77\\Module\\Receipt');
            }
        } catch (\Throwable $e) {
            // Display only — the window works without it and the server decides; the asset missing is a deploy fault.
            error_log('receipt tax-only script: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }
}
