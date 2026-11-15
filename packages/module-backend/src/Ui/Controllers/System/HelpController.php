<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Kibot\HelpKnowledge,
    Z77\Core\Services\HelpAddition,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Auth\AuthUser
;

/**
 * «Hilfe ergänzen» (owner 2026-11-15, ADR-048 addendum): an admin adds the installation's own
 * text to a help — shown below the help in the help window, and read by the KI-Assistent
 * (ADR-055, `HelpKnowledge` appends it to the help's topic).
 *
 *   GET  /backend/system/help/edit?topic=<help topic id>  → the edit window (textarea)
 *   POST /backend/system/help/edit?topic=<help topic id>  → save; empty text removes the addition
 *
 * Who: an ADMIN (or above) of the backend realm — the module role, AND re-checked here
 * ({@see isEditor()}), so a project that opens the controller to a lower role does not hand
 * out writing into the assistant's knowledge with it. The pencil in the help window is only a
 * hint the shell gives such a user (`HelpService::editAt()` in `BackendAbstractController`).
 *
 * What: plain text into `data/help/<topic id>.txt` ({@see HelpAddition}) — never a template,
 * never PHP. The topic id must name an existing help template of the installation (template
 * discovery, {@see HelpKnowledge::templates()}); anything else is a 404. CSRF: a fetch POST is
 * checked by AccessGuard (`X-CSRF-Token`), a page POST by `#[Csrf]`.
 *
 * The save answers the window with `close-window` and `help-addition` {topic, title, paragraphs}:
 * core.js rebuilds the addition from the texts (no markup) and swaps it in the help templates of
 * the page and in the open help window — no reload.
 */
final class HelpController extends BackendAbstractController
{
    /** The edit window's address; the shell appends `?topic=<id>`. */
    public const EDIT_URL = '/backend/system/help/edit';

    /** The lowest role that writes help additions (re-checked in the action). */
    public const ROLE = AuthRole::ADMIN;

    private const ENTITY = 'help-addition';

    /** Whether `$user` may write help additions: a logged-in backend user, admin or above. */
    public static function isEditor(AuthUser $user): bool
    {
        return $user->isLoggedIn() && $user->isBackendRealm() && $user->hasAtLeast(self::ROLE);
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!self::isEditor(DI::getAuthService()->getCurrentUser())) {
            return $this->refuse('Nur ein Admin kann die Hilfe ergänzen.');
        }

        $topic     = (string) $request->getGetParameter('topic');
        $templates = HelpAddition::isValidId($topic) ? HelpKnowledge::templates(DI::getFileFinder()) : [];
        if (!isset($templates[$topic])) {
            return $this->refuse('Diese Hilfe gibt es nicht.');
        }

        $additions = $this->help->additions();
        $text      = $additions->load($topic);
        $error     = '';

        if ($request->isPost()) {
            $text = (string) ($request->getPostParameters()['addition'] ?? '');
            try {
                $additions->save($topic, $text);
                return $this->saved($topic, $additions->load($topic) === '');
            } catch (\InvalidArgumentException) {
                $error = 'Höchstens ' . number_format(HelpAddition::MAX_CHARS, 0, '.', '\'') . ' Zeichen, nur Text.';
            }
        }

        $response = $this->html([
            'window'   => $request->getMode() === RequestMode::Fetch,
            'origin'   => WindowOrigin::of($request),
            'topic'    => $topic,
            'title'    => HelpKnowledge::staticTitle($templates[$topic]),
            'text'     => $text,
            'error'    => $error,
            'maxChars' => HelpAddition::MAX_CHARS,
            'action'   => self::EDIT_URL . '?topic=' . rawurlencode($topic),
            'entity'   => self::ENTITY . ':' . $topic,
        ]);
        $this->layoutManager->addPartials('edit', 'System/HelpController', self::NAMESPACE);

        return $response;
    }

    /**
     * After the save. Fetched (the window): close it, hand core.js the new addition section
     * ('' = removed) for every help of that topic on the page. Without the script: back to the
     * edit page with the flash (there is no help window without JavaScript).
     */
    private function saved(string $topic, bool $removed): FetchResponse|RedirectResponse
    {
        $flash = $removed ? 'Ergänzung zur Hilfe entfernt' : 'Ergänzung zur Hilfe gespeichert';
        if (DI::getRequest()->getMode() !== RequestMode::Fetch) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);
            return $this->redirect(self::EDIT_URL . '?topic=' . rawurlencode($topic), 303);
        }
        $this->messageService->pushFlash('success', $flash);
        return $this->fetch()->setStatus('success')
            ->addCommand('close-window')
            ->addCommand('help-addition', [
                'topic'      => $topic,
                'title'      => DI::getTranslator()->t('common.helpAddition'),
                'paragraphs' => HelpAddition::paragraphs($this->help->additions()->load($topic)),
            ]);
    }

    private function refuse(string $message): FetchResponse|RedirectResponse
    {
        if (DI::getRequest()->getMode() === RequestMode::Fetch) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);
        return $this->redirect('/backend/system/dashboard/overview', 303);
    }
}
