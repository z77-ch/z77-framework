<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * Orchestrates one question (plan section 2, steps c' to g).
 *
 * Round 1 — ROUTING, routing model, not streamed: system prompt with the topic list
 * (id, titel, beschreibung only), the history and the question, plus the tool
 * lies_themen(ids). The model picks 1 to 3 topics, or none.
 *
 * Round 2 — ANSWER, answer model, streamed: the same conversation continues with the
 * routing turn (content blocks unchanged, thinking included) and a user turn carrying
 * the tool_result — the sources, each marked with its title, within the budget. No
 * second system prompt, no second question. If round 1 picked no topic, round 2 gets
 * the history alone plus a note that no source matched, and no tools.
 *
 * Should the answer model still ask for topics, they are read and the answer round
 * repeats, at most api.maxToolRounds times. The budget covers the whole question.
 */
final class Bot
{
    public function __construct(
        private readonly Config $config,
        private readonly AnthropicClient $client,
        private readonly Topics $topics,
        private readonly ?Log $log = null,
    ) {
    }

    /**
     * Wires the bot from the configuration. Relative paths resolve against $baseDir.
     *
     * @throws \RuntimeException when the topics file or the source root is missing
     */
    public static function fromConfig(Config $config, string $baseDir, ?Transport $transport = null): self
    {
        $client = new AnthropicClient(
            new KeyStore($config->path('keyFile', $baseDir)),
            $transport ?? new CurlTransport((int) $config->get('api.timeoutSeconds')),
            (string) $config->get('api.version'),
            (string) $config->get('api.endpoint'),
        );
        $topics = new Topics($config->path('topicsFile', $baseDir), $config->path('sourceRoot', $baseDir));
        return new self($config, $client, $topics, new Log($config, $config->path('logDir', $baseDir)));
    }

    public function topics(): Topics
    {
        return $this->topics;
    }

    /**
     * @param list<string> $previousTopics topics of the last answer
     * @param string       $contextTopic   the topic of the place the question is asked from ('' = none)
     */
    public function systemPrompt(array $previousTopics = [], string $contextTopic = ''): string
    {
        $p     = $this->config->get('prompt');
        $rules = array_merge($p['rules'], [$p['ctaRule']]);
        $text  = $p['intro'] . "\n\nWICHTIGE REGELN:\n- " . implode("\n- ", $rules)
               . "\n\nVORGEHEN:\n- " . implode("\n- ", $p['toolRules'])
               . "\n\nTHEMENLISTE (id | titel: beschreibung):\n" . $this->topics->catalog();
        if ($contextTopic !== '' && $this->topics->has($contextTopic)) {
            $text .= "\n\n" . sprintf(
                (string) $p['contextTopic'],
                $contextTopic . ' (' . $this->topics->title($contextTopic) . ')',
                max(0, $this->maxTopics() - 1)
            );
        }
        if ($previousTopics !== []) {
            $text .= "\n\n" . sprintf($p['previousTopics'], implode(', ', $previousTopics));
        }
        return $text;
    }

    public function tool(): array
    {
        return [
            'name'         => $this->config->get('tool.name'),
            'description'  => $this->config->get('tool.description'),
            'strict'       => true,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'ids' => [
                        'type'        => 'array',
                        'description' => 'Ids aus der Themenliste, 1 bis ' . $this->maxTopics() . '.',
                        // An empty enum is rejected by the API — without topics the server-side check alone answers.
                        'items'       => $this->topics->ids() === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $this->topics->ids()],
                    ],
                ],
                'required'             => ['ids'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Answers the last user message of $messages.
     *
     * @param list<array{role: string, content: string}> $messages cleaned history (Guard::messages)
     * @param list<string>                               $previousTopics topics of the last answer
     * @param null|callable(string): void                $onText receives the answer text while it streams
     * @param string                                     $contextTopic topic of the place the visitor asks from
     *                                                   (e.g. the help of the current screen); unknown ids are ignored
     */
    public function ask(array $messages, array $previousTopics = [], ?callable $onText = null, string $ip = '', string $contextTopic = ''): BotResult
    {
        $onText   ??= static function (string $text): void {};
        $question   = '';
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'user') {
                $question = (string) $m['content'];
                break;
            }
        }
        $previousTopics = array_values(array_filter($previousTopics, fn ($id) => is_string($id) && $this->topics->has($id)));

        $state = ['answer' => '', 'used' => [], 'budget' => (int) $this->config->get('budget.maxChars'), 'truncated' => false];
        $model = (string) $this->config->get('api.routingModel');
        $stop  = null;
        $usage = [];

        try {
            $system = $this->systemPrompt($previousTopics, $contextTopic);
            $tools  = [$this->tool()];

            // ── Round 1: routing ──────────────────────────────────────────────
            $routing = $this->client->create([
                'model'       => $model,
                'max_tokens'  => (int) $this->config->get('api.routingMaxTokens'),
                'system'      => $system,
                'messages'    => $messages,
                'tools'       => $tools,
                'tool_choice' => ['type' => 'auto'],
            ]);
            $usage['routing'] = $routing['usage'] ?? [];
            if (($routing['stop_reason'] ?? null) === 'refusal') {
                return $this->refused($question, $state, $ip, $model, $usage);
            }

            $conversation = $messages;
            $toolUses     = self::toolUses($routing);
            if ($toolUses !== []) {
                $conversation[] = ['role' => 'assistant', 'content' => self::replay($routing['content'])];
                $conversation[] = ['role' => 'user', 'content' => $this->runTools($toolUses, $state)];
            } else {
                $system .= "\n\n" . $this->config->get('prompt.noTopic');
            }

            // ── Round 2: answer (streamed), repeated only for extra tool calls ─
            $model  = (string) $this->config->get('api.answerModel');
            $rounds = 0;
            while (true) {
                $body = [
                    'model'      => $model,
                    'max_tokens' => (int) $this->config->get('api.answerMaxTokens'),
                    'system'     => $system,
                    'messages'   => $conversation,
                ];
                if ($toolUses !== []) { // a conversation with tool blocks must declare the tool
                    $body['tools']       = $tools;
                    $body['tool_choice'] = ['type' => 'auto'];
                }
                $betas = [];
                if ($this->config->get('api.serverSideFallback')) {
                    $body['fallbacks'] = 'default';
                    $betas[] = (string) $this->config->get('api.fallbackBeta');
                }

                $message = $this->client->stream($body, static function (string $text) use (&$state, $onText): void {
                    $state['answer'] .= $text;
                    $onText($text);
                }, $betas);
                $stop             = $message['stop_reason'] ?? null;
                $usage['answer'][] = $message['usage'] ?? [];

                if ($stop === 'refusal') {
                    return $this->refused($question, $state, $ip, $model, $usage);
                }
                $more = self::toolUses($message);
                if ($stop === 'tool_use' && $more !== [] && $rounds < (int) $this->config->get('api.maxToolRounds')) {
                    $rounds++;
                    $toolUses       = $more;
                    $conversation[] = ['role' => 'assistant', 'content' => self::replay($message['content'])];
                    $conversation[] = ['role' => 'user', 'content' => $this->runTools($more, $state)];
                    continue;
                }
                break;
            }

            if (trim($state['answer']) === '') {
                $this->log?->error(200, 'empty answer (stop_reason=' . ($stop ?? 'null') . ', tool rounds=' . $rounds . ')');
                return $this->failed('genericError', $question, $state, $ip, $model, $stop, $usage);
            }
        } catch (ApiException $e) {
            $this->log?->apiError($e, $model);
            $key = match (true) {
                $e->isBusy()            => 'overloaded',
                $e->isConnectionError() => 'connectionFailed',
                default                 => 'genericError',
            };
            return $this->failed($key, $question, $state, $ip, $model, $stop, $usage);
        } catch (\RuntimeException $e) { // KeyStore: no key file, unreadable, empty
            $this->log?->error(0, $e->getMessage());
            return $this->failed('noApiKey', $question, $state, $ip, $model, $stop, $usage);
        }

        $this->log?->conversation($question, $state['answer'], $ip, $this->logExtra($state, $model, $stop, $usage));
        return new BotResult($state['answer'], $state['used'], null, null, $stop);
    }

    /**
     * Executes lies_themen calls and returns the tool_result blocks for the next user turn.
     * Invalid input (not 1..maxTopics known ids) gets is_error so the model can correct it.
     */
    private function runTools(array $toolUses, array &$state): array
    {
        $results = [];
        foreach ($toolUses as $use) {
            $ids = $this->validIds($use);
            if ($ids === null) {
                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $use['id'],
                    'is_error'    => true,
                    'content'     => 'Ungültige Eingabe: "ids" muss 1 bis ' . $this->maxTopics()
                                   . ' Ids aus der Themenliste enthalten. Bekannte Ids: ' . implode(', ', $this->topics->ids()),
                ];
                continue;
            }
            $read = $this->topics->read($ids, max(0, $state['budget']));
            $state['budget']   -= Text::length($read['text']);
            $state['truncated'] = $state['truncated'] || $read['truncated'];
            $state['used']      = array_values(array_unique(array_merge($state['used'], $ids)));
            if ($read['skipped'] !== []) {
                $this->log?->error(0, 'sources skipped (missing, outside the root or not allowed): ' . implode(', ', $read['skipped']));
            }
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $read['text']];
        }
        return $results;
    }

    /** @return list<string>|null */
    private function validIds(array $use): ?array
    {
        if (($use['name'] ?? '') !== $this->config->get('tool.name')) {
            return null;
        }
        $input = $use['input'] ?? null;
        if (!is_array($input) || array_keys($input) !== ['ids'] || !is_array($input['ids']) || !array_is_list($input['ids'])) {
            return null;
        }
        $ids = $input['ids'];
        if ($ids === [] || count($ids) > $this->maxTopics()) {
            return null;
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !$this->topics->has($id)) {
                return null;
            }
        }
        return array_values(array_unique($ids));
    }

    private function maxTopics(): int
    {
        return max(1, (int) $this->config->get('budget.maxTopics'));
    }

    /** @return list<array> the tool_use blocks of a message */
    private static function toolUses(array $message): array
    {
        return array_values(array_filter(
            $message['content'] ?? [],
            static fn ($b) => is_array($b) && ($b['type'] ?? '') === 'tool_use' && isset($b['id'])
        ));
    }

    /**
     * An assistant turn goes back exactly as received. The one exception is JSON shape:
     * a decoded empty tool input is a PHP [] and would be re-encoded as a JSON array;
     * the API needs an object. Input that never parsed (null) also goes back as {}.
     */
    private static function replay(array $content): array
    {
        foreach ($content as &$block) {
            if (($block['type'] ?? '') === 'tool_use' && (!is_array($block['input'] ?? null) || $block['input'] === [])) {
                $block['input'] = new \stdClass();
            }
        }
        unset($block);
        return $content;
    }

    private function refused(string $question, array $state, string $ip, string $model, array $usage): BotResult
    {
        $this->log?->error(200, 'refusal (model=' . $model . ')');
        return $this->failed('refusal', $question, $state, $ip, $model, 'refusal', $usage);
    }

    private function failed(string $key, string $question, array $state, string $ip, string $model, ?string $stop, array $usage): BotResult
    {
        $message = $this->config->message($key);
        $this->log?->conversation($question, $state['answer'], $ip, ['error' => $key] + $this->logExtra($state, $model, $stop, $usage));
        return new BotResult($state['answer'], $state['used'], $message, $key, $stop);
    }

    private function logExtra(array $state, string $model, ?string $stop, array $usage): array
    {
        return [
            'topics'      => $state['used'],
            'truncated'   => $state['truncated'],
            'model'       => $model,
            'stop_reason' => $stop,
            'usage'       => $usage,
        ];
    }
}
