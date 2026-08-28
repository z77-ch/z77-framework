<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\BlockedCountry,
    Z77\Shared\Forms\CountryBlocklist,
    Z77\Shared\Forms\FormLog,
    Z77\Shared\GeoIp\CountryLookup
;

/**
 * «Formular-Protokoll» — every submit of every geo-guarded public form, with
 * its origin, and the country blocklist that is decided FROM it. Logic and
 * templates live here (like BackupController / JobController) — the log and
 * the blocklist are kernel data, so no module fragment is mounted.
 *
 * This surface exists to be LOOKED AT before anything is blocked. The gates
 * (honeypot, time trap, throttles) already do their work silently; what was
 * missing was any way to see whether something systematic is happening, and
 * from where. A country rule decided without this list would be a guess —
 * which is exactly why the blocklist is edited HERE, one click away from the
 * tally that justifies it, and not in a config file a deploy away from the
 * evidence.
 *
 * ⚠️ The visitor never learns the difference this page shows. A bot, a
 * throttled flood and a genuine submit all leave the same answer — that
 * indistinguishability is a security property (anti-oracle, MEM-005) and is
 * not to be traded away for a nicer error message. The knowledge lives here,
 * server-side, where knowing it costs nothing.
 *
 * ⚠️ PERSONAL DATA. Rows carry an IP, a country, a user agent, and — where a
 * form's FormDefinition::identityField() opted in — an identifying value.
 * That is a different purpose from the abuse counters — which keep only a
 * hash, and only for their counting window — so it has its own paragraph in
 * the privacy policy and its own retention ({@see FormLog::RETENTION_DAYS}).
 * Do not add a column without adding a sentence there.
 *
 * ⚠️ The MaxMind attribution at the foot of the page is a LICENCE TERM of the
 * GeoLite EULA, not decoration: it must stay wherever the country results are
 * displayed. Removing it breaks the licence the database is used under.
 */
class FormLogController extends BackendAbstractController
{
    /** How many log lines the page shows — and counts its tallies over. */
    private const SHOW_ROWS = 300;

    /**
     * Flow details that EXPLAIN a `failed` outcome instead of being one.
     *
     * ⚠️ `PublicFormHandler` has one return value, so a gate that refused
     * (a throttle) and a mail server that broke both arrive as «failed».
     * Displayed as such they read alike — a red «Versand scheiterte» for a
     * gate doing exactly its job. An operator who sees that alarm three times
     * without cause stops seeing it the fourth.
     *
     * The substitution happens HERE, once, so the tally and the row can never
     * disagree: the controller says what a row IS, the template only says how
     * it looks. (The country rule needs no entry any more — it is the
     * first-class outcome `geo` since the gate moved into the handler.)
     */
    private const GATE_DETAILS = ['throttled', 'throttled-ip'];

    /** URL root — every button and every modal form is built from it. */
    private const ACTION_BASE = '/backend/service/form-log';

    protected function listAction(): HtmlResponse
    {
        $rows = FormLog::recent(self::SHOW_ROWS);

        // The distinct form keys of the WHOLE window, so the filter offers a
        // form even while another one floods the list.
        $forms = [];
        foreach ($rows as $row) {
            $key = (string)($row['form'] ?? '');
            if ($key !== '') {
                $forms[$key] = true;
            }
        }
        $forms = array_keys($forms);
        sort($forms);

        $filter = trim((string)DI::getRequest()->getGetParameter('form'));
        if ($filter !== '' && in_array($filter, $forms, true)) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (string)($row['form'] ?? '') === $filter,
            ));
        } else {
            $filter = '';
        }

        foreach ($rows as $i => $row) {
            $outcome = (string)($row['outcome'] ?? '') ?: '?';
            $detail  = (string)($row['detail'] ?? '');
            $rows[$i]['effective'] = $outcome === 'failed' && in_array($detail, self::GATE_DETAILS, true)
                ? $detail
                : $outcome;
        }

        // ⚠️ Read failure is a VISIBLE state, not a 500. The gate fails open
        // on a corrupt store (CountryBlocklist::codes() answers []), so the
        // rule is OFF — and this page is the only place that can say so. If
        // the read threw here, a broken file would switch the rule off
        // silently AND kill the one surface that would show it. The WRITE
        // side below still throws: a failed save has to be visible.
        $blocked       = [];
        $blockedBroken = false;
        try {
            foreach ($this->countryBlocklist()->all() as $entry) {
                $blocked[$entry->getCode()] = $entry;
            }
        } catch (\Throwable $e) {
            $blockedBroken = true;
            error_log('FormLogController: blocklist unreadable - ' . $e->getMessage());
        }

        $builtAt = CountryLookup::databaseBuiltAt();

        return $this->html([
            'rows'          => $rows,
            'byCountry'     => $this->countryTally($rows),
            'byOutcome'     => $this->outcomeTally($rows),
            'blocked'       => $blocked,
            'blockedBroken' => $blockedBroken,
            'forms'         => $forms,
            'filter'        => $filter,
            'total'         => count($rows),
            'limit'         => self::SHOW_ROWS,
            'retention'     => FormLog::RETENTION_DAYS,
            'actionBase'    => self::ACTION_BASE,
            // Whether the country column can mean anything at all, and how
            // stale it is. An operator reading «??» everywhere should see the
            // reason on the same page, not go hunting for it.
            'geoReady'      => CountryLookup::available(),
            'geoBuilt'      => $builtAt,
            // ⚠️ Licence term, see the class docblock.
            'geoNotice'     => CountryLookup::ATTRIBUTION,
        ]);
    }

    /**
     * Confirm blocking a country — with the tally that justifies it already
     * written into the reason field.
     *
     * The prefilled sentence is the whole point of doing this here: in a year
     * «RU gesperrt» says nothing, «x Versuche, davon 0 angenommen» is a
     * decision someone can review and reverse. The operator may overwrite it.
     */
    protected function confirmBlockAction(): HtmlResponse|FetchResponse
    {
        $code = BlockedCountry::normalizeCode(
            (string)DI::getRequest()->getGetParameter('code')
        );
        if ($code === '') {
            return $this->fetchError('Kein gültiges Länderkürzel');
        }
        if ($this->countryBlocklist()->has($code)) {
            return $this->fetchError('Dieses Land steht bereits auf der Sperrliste');
        }

        $rows = FormLog::recent(self::SHOW_ROWS);

        $response = $this->html([
            'code'       => $code,
            'reason'     => $this->suggestedReason($code, $rows),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('blockedCountry', $code),
            'actionBase' => self::ACTION_BASE,
        ]);
        $this->layoutManager->addPartials('confirmBlock', 'Service/FormLogController', self::NAMESPACE);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function blockAction(): FetchResponse
    {
        [$code, $error] = $this->countryCodeFromPost();
        if ($error !== null) {
            return $error;
        }

        $body   = DI::getRequest()->getJsonBody();
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            return $this->fetchError('Ohne Grund keine Sperre — in einem Jahr weiss sonst niemand mehr warum');
        }

        $entry = $this->countryBlocklist()->block($code, $reason, $this->operatorName());
        if ($entry === null) {
            return $this->fetchError('Dieses Land steht bereits auf der Sperrliste');
        }

        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Land «' . $code . '» gesperrt — Formulare mit Geo-Guard weisen Übermittlungen von dort ab sofort ab.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    /** Confirm lifting a block — shows the reason it was entered under. */
    protected function confirmUnblockAction(): HtmlResponse|FetchResponse
    {
        $code  = (string)DI::getRequest()->getGetParameter('code');
        $entry = $this->countryBlocklist()->find($code);
        if ($entry === null) {
            return $this->fetchError('Dieses Land steht nicht auf der Sperrliste');
        }

        $response = $this->html([
            'entry'      => $entry,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('blockedCountry', $entry->getCode()),
            'actionBase' => self::ACTION_BASE,
        ]);
        $this->layoutManager->addPartials('confirmUnblock', 'Service/FormLogController', self::NAMESPACE);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function unblockAction(): FetchResponse
    {
        [$code, $error] = $this->countryCodeFromPost();
        if ($error !== null) {
            return $error;
        }

        if (!$this->countryBlocklist()->unblock($code)) {
            return $this->fetchError('Dieses Land steht nicht auf der Sperrliste');
        }

        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Sperre für «' . $code . '» aufgehoben — Übermittlungen von dort laufen wieder normal.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    // ── shared plumbing ────────────────────────────────────────────────────

    /**
     * WHERE the attempts come from. Counted over the rows actually shown, so
     * the numbers always match the list below them — a summary over a wider
     * set than the list is a summary nobody can check.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,int> country code (or '??') → count, biggest first
     */
    private function countryTally(array $rows): array
    {
        $tally = [];
        foreach ($rows as $row) {
            $country = (string)($row['country'] ?? '') ?: '??';
            $tally[$country] = ($tally[$country] ?? 0) + 1;
        }
        arsort($tally);

        return $tally;
    }

    /**
     * WHAT the gates made of them — the second question, deliberately a
     * separate tally from the first.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function outcomeTally(array $rows): array
    {
        $tally = [];
        foreach ($rows as $row) {
            $outcome = (string)($row['effective'] ?? $row['outcome'] ?? '?');
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }
        arsort($tally);

        return $tally;
    }

    /**
     * The sentence offered as the reason: how many attempts came from this
     * country, and how many of them were accepted. Those two numbers are the
     * whole case — a country with many attempts and no acceptances is the
     * pattern the rule is for.
     *
     * ⚠️ The sentence NAMES ITS WINDOW. The tally counts only the newest
     * {@see self::SHOW_ROWS} lines; under a flood that window is hours, and a
     * legitimate country whose accepted submits are older shows «0
     * angenommen» — a number that reads like evidence and is an artefact of
     * the window. Naming the window keeps the prefilled reason honest.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function suggestedReason(string $code, array $rows): string
    {
        $attempts = 0;
        $accepted = 0;
        foreach ($rows as $row) {
            if ((string)($row['country'] ?? '') !== $code) {
                continue;
            }
            $attempts++;
            if ((string)($row['outcome'] ?? '') === 'sent') {
                $accepted++;
            }
        }

        return sprintf(
            '%d Versuche in den letzten %d Protokollzeilen, davon %d angenommen (Stand %s).',
            $attempts,
            count($rows),
            $accepted,
            date('d.m.Y')
        );
    }

    /**
     * Code out of the POST body, CSRF-checked.
     *
     * @return array{0: string, 1: ?FetchResponse}
     */
    private function countryCodeFromPost(): array
    {
        $body = DI::getRequest()->getJsonBody();
        $code = BlockedCountry::normalizeCode((string)($body['code'] ?? ''));
        if ($code === '') {
            return ['', $this->fetchError('Kein gültiges Länderkürzel')];
        }
        if (!DI::getCsrfService()->validateEntityToken(
            trim((string)($body['entity_csrf'] ?? '')),
            'blockedCountry',
            $code,
        )) {
            return ['', $this->fetchError('Invalid token')];
        }

        return [$code, null];
    }

    /** Who to ask about a reason later. Null when the name is not available. */
    private function operatorName(): ?string
    {
        $name = trim(DI::getCurrentUserService()->getBackendUser()?->getUsername() ?? '');

        return $name !== '' ? $name : null;
    }

    private function countryBlocklist(): CountryBlocklist
    {
        return CountryBlocklist::create();
    }
}
