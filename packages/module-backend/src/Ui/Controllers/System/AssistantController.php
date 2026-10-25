<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Backend\Ui\Assistant\BackendAssistant,
    Z77\Core\DI,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\VoidResponse,
    Z77\Core\Kibot\KibotEndpoint,
    Z77\Core\Kibot\KibotSession,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The backend KI-Assistent's two routes (ADR-055, docs/topics/kibot.md):
 *
 *   GET  /backend/system/assistant/config  → panel texts + CSRF token (JSON)
 *   POST /backend/system/assistant/ask     → {messages, token, website, context} → answer as SSE
 *
 * Every logged-in backend user (EDITOR, the lowest backend role — backendConfig). Both are
 * Fetch-only like the website bot's; the POST passes AccessGuard's CSRF check
 * (X-CSRF-Token, sent by bot.js) before it gets here, Guard re-checks the same token.
 * `context` is the help topic of the screen the panel was opened from; the bot reads it
 * first. Off (404) while the assistant is not configured — the shell then shows the classic
 * «Hilfe» and never calls here.
 *
 * Only the question, the history and the help texts reach the API — no record, no session
 * data: the assistant knows the operation, not the data (ADR-055).
 */
final class AssistantController extends BackendAbstractController
{
    #[Fetch, HttpMethod('GET')]
    protected function configAction(): JsonResponse
    {
        return $this->json($this->endpoint()->widget(DI::getCsrfService()->getToken()));
    }

    #[Fetch, HttpMethod('POST')]
    protected function askAction(): VoidResponse
    {
        $endpoint = $this->endpoint();
        $request  = DI::getRequest();

        // A deploy with changed help texts updates the knowledge here, on the first question.
        BackendAssistant::refresh(BackendAssistant::knowledge($endpoint), $endpoint);

        $endpoint->stream(
            $request->getJsonBody(),
            (string) $request->getClientIp(),
            new KibotSession(DI::getSessionManager(), DI::getCsrfService())
        );

        return $this->void();
    }

    private function endpoint(): KibotEndpoint
    {
        return BackendAssistant::fromConfig() ?? throw new NotFoundException('The KI-Assistent is not configured.');
    }
}
