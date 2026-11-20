<?php
namespace Z77\Module\Mandator\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Mandator\Entities\Mandator,
    Z77\Module\Mandator\Logo\LogoDrive,
    Z77\Module\Mandator\Logo\LogoImage,
    Z77\Module\Mandator\Logo\LogoService,
    Z77\Module\Mandator\Logo\LogoUses,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Upload\UploadPolicy,
    Z77\Module\Mandator\Services\CurrentMandator,
    Z77\Module\Mandator\Services\InvalidMandatorException,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Module\Mandator\Services\MandatorAccounts,
    Z77\Module\Mandator\Services\MandatorAlreadyExistsException,
    Z77\Module\Mandator\Services\MandatorService,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf
;

/**
 * The mandator screen (owner decisions E1 / E2, 2026-09-23) — ONE record,
 * so ONE page: `edit` shows the existing mandator or, while there is none,
 * an empty form with the KMU account start values pre-filled
 * ({@see MandatorAccounts::prefilled()}); the first save creates the
 * record, every later save changes it. No list, no «Neu» button, no delete.
 * Mounted by a thin host controller in the backend (ADR-018 pattern). Host
 * side: `use MandatorControllerTrait` + a one-line layout config delegating
 * to {@see MandatorLayout::config()}, and `defaultAction` = `edit` in
 * `backendConfig` (the `report` model — there is no list).
 *
 * A PAGE with a plain `<form method="post">` and `#[Csrf]` (`csrf_token`),
 * like the journal forms — the record has thirty fields, a modal is the
 * wrong size. An existing record additionally carries the entity token
 * `mandator`. The account fields are typed by number with a `<datalist>` of
 * the postable accounts when module-financial is registered
 * ({@see LedgerAccountCheck::postableAccounts()}); each shows its status
 * (ok / prüfen / ungeprüft) so a wrong account is found here and not when
 * a posting refuses it. No JavaScript of its own (Rule 7). The three sections
 * are radio tabs (owner 2026-10-08): ONE form, the panels switched by CSS,
 * the tab labels in the toolbar (`editToolbar`, hc2); a refused save opens
 * the tab of the first field error ({@see MandatorLayout::openTab()}).
 *
 * When the record CANNOT be read — table missing, module not registered
 * ({@see CurrentMandator::unavailableReason()}) — the page shows the German
 * sentence as a band and no form (review 2026-09-23: a message, never a
 * 500). Every write goes through {@see MandatorService}; the managed record
 * is never mutated here before validation (ADR-039 decision 9) — the cleaned
 * values go to the service, a refused change comes back as the exception's
 * draft. The using class MUST provide (via its host base): `html()`,
 * `redirect()`, `fetch()`, `fetchError()`, `em()`, `$layoutManager`, `$messageService`, `$help`
 * (the page's help, ADR-048).
 *
 * Since 2026-11-20 also the logo set-up WINDOW (`logoAction()`, `/…/mandator/logo`): the four uses
 * of the company logo, one upload for all or one per use, the variants derived and stored in the
 * DMS by `Logo\LogoService` — opened from the setup step and from the tab «Briefkopf».
 */
trait MandatorControllerTrait
{
    private const MANDATOR_NS = 'Z77\\Module\\Mandator';

    /** URL root of THIS mount — the form posts to it. */
    protected function mandatorBase(): string
    {
        return '/backend/finance/mandator';
    }

    private function mandatorService(): MandatorService
    {
        return new MandatorService($this->em());
    }

    /**
     * GET: the record (or the pre-filled empty form). POST: create or
     * update through the service; success redirects back here with a flash,
     * a refusal re-renders the form with the draft and its field errors.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|RedirectResponse
    {
        $request     = DI::getRequest();
        $reader      = new CurrentMandator($this->em());
        $unavailable = $reader->unavailableReason();
        if ($unavailable !== null) {
            return $this->html(['unavailable' => $unavailable, 'actionBase' => $this->mandatorBase()]);
        }
        $current   = $reader->find();
        $shown     = $current;
        $validator = null;

        if ($request->isPost()) {
            $post   = $request->getPostParameters();
            $values = BodyCleaner::cleanFor(Mandator::class, $post);
            unset($values['id']);
            // An unchecked checkbox is not submitted — the hidden `liable_to_vat=0` before it is.
            $values['liable_to_vat'] = (bool) ($post['liable_to_vat'] ?? false);

            try {
                if ($current === null) {
                    $shown = new Mandator($values);
                    $this->mandatorService()->save($shown);
                } else {
                    $csrf = trim((string) ($post['entity_csrf'] ?? ''));
                    if (!DI::getCsrfService()->validateEntityToken($csrf, 'mandator', $current->getId())) {
                        $this->messageService->pushFlashAfterRedirect('error', 'Ungültiges Formular-Token — bitte neu laden.');

                        return $this->redirect($this->mandatorBase() . '/edit', 303);
                    }
                    $this->mandatorService()->update($current, $values);
                }
                $this->messageService->pushFlashAfterRedirect('success', 'Mandant «' . $shown->getName() . '» gespeichert');

                return $this->redirect($this->mandatorBase() . '/edit', 303);
            } catch (InvalidMandatorException $e) {
                $validator = $e->validator;
                $shown     = $e->mandator;   // the refused draft — never the managed entity
            } catch (MandatorAlreadyExistsException $e) {
                // Two admins saved the first record at once (the primary key decided); the second sees the first's.
                $this->messageService->pushFlashAfterRedirect('error', $e->getMessage());

                return $this->redirect($this->mandatorBase() . '/edit', 303);
            }
        }

        $shown   ??= MandatorAccounts::prefilled();
        $check     = new LedgerAccountCheck($this->em());
        // Field errors only after a refused POST; on GET an un-run validator (no errors). What BECAME
        // invalid since the save is flagged by `accountStatus` («prüfen»), not by a field error.
        $validator ??= $this->mandatorService()->validator($shown);

        // The page's help (ADR-048): «? Hilfe» in the top bar, F1 in a field opens its section.
        $this->help->attach('Backend/MandatorController/edit.help', self::MANDATOR_NS, [], 'Hilfe: Mandant');
        $response = $this->html([
            'unavailable'   => null,
            'entry'         => $shown,
            'isNew'         => $current === null,
            'validator'     => $validator,
            // The sections are radio tabs (owner 2026-10-08); a refused save opens the tab with the
            // first field error, otherwise the first tab.
            'mandatorTabs'  => MandatorLayout::TABS,
            'openTab'       => MandatorLayout::openTab($validator),
            'entityCsrf'    => $current === null ? '' : DI::getCsrfService()->generateEntityToken('mandator', $current->getId()),
            'accountKeys'   => array_keys(Mandator::ACCOUNT_KEYS),
            'accountLabels' => MandatorAccounts::LABELS,
            'accountStatus' => (new MandatorAccounts($this->em()))->status($shown),
            'accounts'      => $check->postableAccounts(),
            'ledgerKnown'   => $check->available(),
            'actionBase'    => $this->mandatorBase(),
            // The logo block of «Briefkopf» (2026-11-20): what each use shows today, «Firmenlogo …» opens the window.
            'logoRows'      => $current !== null ? $this->mandatorLogoRows($current) : [],
            'logoWindow'    => $current !== null && LogoDrive::available(),
        ]);
        // The fragment owns its header slot: «Speichern» in the action cell, the entry's most
        // frequent action (ADR-033 rev. 2026-10-08) — it submits the form via `form="mandator-edit"`.
        $this->layoutManager->addPartials('editAct', 'Backend/MandatorController', MandatorLayout::NS, 'hc1');
        // … and the section tabs in the toolbar: `<label for>` of the radios in the form (pure CSS).
        $this->layoutManager->addPartials('editToolbar', 'Backend/MandatorController', MandatorLayout::NS, 'hc2');

        return $response;
    }

    // ── the logo set-up window (owner decisions 2026-11-20) ─────────────

    /**
     * «Firmenlogo» — an ADR-047 window (opened from the setup step «Firmenlogo … hinterlegen» and from
     * the tab «Briefkopf»; without the script the same address is a page). GET shows the four uses
     * with what each shows today; POST runs what the hidden `op` names:
     *
     *   - `save`    — «ein Logo für alle» (`one_for_all`) on: ONE file `logo_all` serves every use the
     *                 installation shows; off: one optional file per use, `logo_<use>`. The server
     *                 derives and stores the variants ({@see LogoService::save()});
     *   - `remove`  — `use`: that use's window logo goes to the Drive's trash (that use then
     *                 shows no logo; the login falls back to the e-mail logo);
     *   - `dismiss` — «Später / kein Logo»: the setup step stays silent.
     *
     * Gate: the screen's right (module role ADMIN) and `#[Csrf]`; every posted value is judged again
     * here — a use must be one the window offers, a file must arrive whole; the content check is
     * `LogoImage`'s. No name, no path from the request reaches the store.
     */
    #[Csrf]
    protected function logoAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request     = DI::getRequest();
        $reader      = new CurrentMandator($this->em());
        $unavailable = $reader->unavailableReason();
        $mandator    = $unavailable === null ? $reader->find() : null;
        if (!$request->isPost()) {
            return $this->mandatorLogoWindow($mandator, $unavailable, [], null);
        }
        if ($mandator === null || !LogoDrive::available()) {
            return $this->mandatorLogoRefuse($unavailable ?? ($mandator === null
                ? 'Zuerst den Mandanten erfassen (Stammdaten › Firma › Mandant).'
                : 'Für das Hochladen braucht es die Ablage (z77/module-dms).'));
        }

        $post    = $request->getPostParameters();
        $service = new LogoService($this->em());
        switch ((string) ($post['op'] ?? '')) {
            case 'dismiss':
                $service->dismiss($mandator);

                return $this->mandatorLogoDone('Firmenlogo: der Schritt ist ausgeblendet. Hochladen geht jederzeit unter Mandant › Briefkopf.');
            case 'remove':
                $use = (string) ($post['use'] ?? '');
                if (!in_array($use, LogoUses::shown(), true)) {
                    return $this->mandatorLogoRefuse('Unbekannte Verwendung.');
                }
                $service->remove($mandator, $use);

                return $this->mandatorLogoDone('Logo «' . LogoUses::LABELS[$use] . '» entfernt — die Datei liegt im Papierkorb der Ablage.');
            case 'save':
                $oneForAll = !empty($post['one_for_all']);
                $uploads   = [];
                $errors    = [];
                $fields    = $oneForAll ? ['logo_all' => LogoUses::served()] : array_combine(
                    array_map(static fn(string $use): string => 'logo_' . $use, LogoUses::served()),
                    array_map(static fn(string $use): array => [$use], LogoUses::served())
                );
                foreach ($fields as $field => $uses) {
                    $file = $request->getUploadedFile($field);
                    if ($file === null) {
                        continue;
                    }
                    $bytes = $file->isOk() && $file->size >= 1 && $file->size <= LogoImage::MAX_UPLOAD_BYTES ? $file->bytes() : null;
                    if ($bytes === null || $bytes === '') {
                        $errors[$field] = 'Die Datei ist nicht angekommen oder zu gross (höchstens 5 MB) — bitte nochmals wählen.';
                        continue;
                    }
                    foreach ($uses as $use) {
                        $uploads[$use] = $bytes;
                    }
                }
                if ($errors === [] && $uploads === []) {
                    $errors[$oneForAll ? 'logo_all' : ''] = $oneForAll ? 'Bitte ein Logo wählen.' : 'Bitte mindestens ein Logo wählen.';
                }
                if ($errors === []) {
                    foreach ($service->save($mandator, $uploads) as $use => $message) {
                        $errors[$oneForAll ? 'logo_all' : 'logo_' . $use] ??= $message;
                    }
                }
                if ($errors !== []) {
                    return $this->mandatorLogoWindow($mandator, null, $errors, $oneForAll);
                }

                // Never suggest «everywhere» when the project keeps its own design for some uses (owner 2026-11-20).
                $own = LogoUses::ownDesigned();

                return $this->mandatorLogoDone($own === []
                    ? 'Firmenlogo gespeichert.'
                    : 'Firmenlogo gespeichert — ' . LogoUses::words($own) . (count($own) === 1 ? ' nutzt' : ' nutzen') . ' das eigene Design dieses Projekts.');
        }

        return $this->mandatorLogoRefuse('Unbekannte Aktion.');
    }

    /**
     * What each use shows today — for the window and the logo block of «Briefkopf».
     *
     * @return list<array{use: string, label: string, state: 'document'|'none'|'own', preview: string|null}>
     */
    private function mandatorLogoRows(Mandator $mandator): array
    {
        $drive = LogoDrive::available() ? new LogoDrive() : null;
        $rows  = [];
        foreach (LogoUses::shown() as $use) {
            $row = ['use' => $use, 'label' => LogoUses::LABELS[$use], 'state' => 'none', 'preview' => null];
            if (LogoUses::ownDesign($use)) {
                $row['state'] = 'own';
            } elseif ($mandator->logoDocument($use) !== null) {
                $row['state']   = 'document';
                $row['preview'] = $drive?->previewUrl($use, (int) $mandator->logoDocument($use));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Renders the logo window (or page).
     *
     * @param array<string, string> $errors field → German sentence; '' = the whole form
     */
    private function mandatorLogoWindow(?Mandator $mandator, ?string $unavailable, array $errors, ?bool $oneForAll): HtmlResponse
    {
        $request  = DI::getRequest();
        $rows     = $mandator !== null ? $this->mandatorLogoRows($mandator) : [];
        $missing  = array_filter($rows, static fn(array $row): bool => $row['state'] === 'none');
        $policy   = fn(string $field): UploadPolicy => new UploadPolicy(
            endpoint:   $this->mandatorBase() . '/logo',
            field:      $field,
            multiple:   false,
            accept:     ['.png', '.jpg', '.jpeg', '.gif'],
            maxBytes:   LogoImage::MAX_UPLOAD_BYTES,
            onConflict: UploadPolicy::CONFLICT_ERROR,
            shape:      'field',
            hint:       'PNG, JPG oder GIF · bis 5 MB',
        );
        $policies = ['logo_all' => $policy('logo_all')];
        foreach (LogoUses::served() as $use) {
            $policies['logo_' . $use] = $policy('logo_' . $use);
        }

        $this->help->attach('Backend/MandatorController/logo.help', self::MANDATOR_NS, [], 'Hilfe: Firmenlogo');
        $response = $this->html([
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'unavailable' => $unavailable ?? ($mandator === null ? 'Noch kein Mandant erfasst — zuerst den Mandanten anlegen (Stammdaten › Firma › Mandant), dann das Logo.' : null),
            'dmsMissing'  => !LogoDrive::available(),
            'mandator'    => $mandator,
            'rows'        => $rows,
            'served'      => LogoUses::served(),
            'own'         => LogoUses::ownDesigned(),
            'oneForAll'   => $oneForAll ?? true,
            'errors'      => $errors,
            'policies'    => $policies,
            // «Später / kein Logo» while the step would be shown: something missing and not dismissed yet.
            'canDismiss'  => $mandator !== null && $missing !== [] && !$mandator->isLogoStepDismissed(),
            'actionBase'  => $this->mandatorBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('logo', 'Backend/MandatorController', MandatorLayout::NS);

        return $response;
    }

    /** Done: in a window the window closes and the page behind reloads (dashboard step, Briefkopf previews); as a page, back to it. */
    private function mandatorLogoDone(string $flash): FetchResponse|RedirectResponse
    {
        $this->messageService->pushFlashAfterRedirect('success', $flash);
        if (!ListDefinition::isFetch(DI::getRequest())) {
            return $this->redirect($this->mandatorBase() . '/logo', 303);
        }

        return $this->fetch()->setStatus('success')->addCommand('close-window')->addCommand('reload');
    }

    private function mandatorLogoRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->mandatorBase() . '/edit', 303);
    }
}
