<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Import\ImportOutcome,
    Z77\Shared\Import\ImportPlan,
    Z77\Shared\Import\ImportPlanEntry,
    Z77\Shared\Import\ImportService,
    Z77\Shared\Import\ImportServiceFactory,
    Z77\Shared\Import\ImportSourceException,
    Z77\Shared\Import\ImportStaleException,
    Z77\Shared\Jobs\JobQueue
;

/**
 * Backend surface of the data import (ADR-032): pick a source (vendor defaults
 * or a staged inbox file), review the computed plan, decide per record, apply.
 * All logic lives in the kernel import classes; this is thin glue and display
 * building (the template renders arrays, it never calls services —
 * NAV-LIST-VM-001 lesson).
 *
 * The plan is never patched: every decision is stored and the plan recomputed
 * from source + decisions, so an assignment can unblock dependents. Apply runs
 * in-request for small plans and as the `import-apply` job beyond
 * {@see self::JOB_THRESHOLD} accepted records.
 *
 * SUPER_USER on every action (installation governance, like backup/jobs).
 * URL: /backend/service/import/{action}. Mutations are Fetch POSTs.
 */
class ImportController extends BackendAbstractController
{
    /** Accepted records beyond this run as a job — a bulk apply is not request work. */
    private const JOB_THRESHOLD = 50;

    /**
     * Display groups, in the order the developer should work through them. These
     * are NOT the planner's outcomes: `changed` is split by what the change
     * actually MEANS — a pure bookkeeping key backfill (harmless, bulk-acceptable)
     * versus a real content difference that deserves a per-record look. The core
     * outcome model (ADR-032 §6) stays untouched; this is presentation.
     *
     * Each group carries the sentence that explains it — the screen must be
     * readable by someone who has not touched the import for six months.
     */
    private const GROUPS = [
        'unclear' => [
            'label' => 'Zuordnung nötig',
            'hint'  => 'Diese Einträge liefert das Framework mit. Ob sie deinen bestehenden '
                     . 'entsprechen, kann der Import nicht sicher entscheiden — deine tragen noch '
                     . 'keine Kennung. Ordne zu, was dasselbe ist; der Rest wird neu angelegt.',
        ],
        'changed-content' => [
            'label' => 'Inhalt weicht ab',
            'hint'  => 'Denselben Eintrag gibt es bei dir, aber mit anderem Inhalt — meist weil '
                     . 'das Framework etwas korrigiert hat oder du selbst angepasst hast. Nichts '
                     . 'wird ohne dein Zutun überschrieben.',
        ],
        'new' => [
            'label' => 'Neu',
            'hint'  => 'Gibt es bei dir noch nicht — wird angelegt.',
        ],
        'changed-key' => [
            'label' => 'Kennung nachtragen',
            'hint'  => 'Diese Einträge hast du bereits und sie bleiben unverändert. Sie bekommen '
                     . 'nur die feste Framework-Kennung, an der ein künftiger Import sie '
                     . 'wiedererkennt — ohne sie fragt jeder Import erneut nach. Für Besucher '
                     . 'ändert sich nichts.',
        ],
        'blocked' => [
            'label' => 'Wartet auf anderes',
            'hint'  => 'Hängt an einem Eintrag weiter oben, der noch offen ist. Löst sich von '
                     . 'selbst, sobald der entschieden ist.',
        ],
        'invalid' => [
            'label' => 'Nicht importierbar',
            'hint'  => 'Die Quelle liefert diese Datensätze in einer Form, die nicht zur Entity passt.',
        ],
        'skipped' => [
            'label' => 'Bereits vorhanden',
            'hint'  => 'Identisch bei dir vorhanden — nichts zu tun.',
        ],
    ];

    /** Field name → German label. Unknown fields fall back to the raw key. */
    private const FIELD_LABELS = [
        'key'            => 'Kennung',
        'name'           => 'Name',
        'module'         => 'Modul',
        'group'          => 'Gruppe',
        'controller'     => 'Controller',
        'action'         => 'Action',
        'slot'           => 'Render-Slot',
        'param'          => 'UI-Parameter',
        'active'         => 'Aktiv',
        'ref'            => 'Verweis',
        'parent_id'      => 'Übergeordnet',
        'path'           => 'Pfad',
        'is_canonical'   => 'Kanonisch',
        'navigation_id'  => 'Navigation',
        'language'       => 'Sprache',
        'title'          => 'Titel',
        'description'    => 'Beschreibung',
        'theme_color'    => 'Themenfarbe',
        'application_ld' => 'JSON-LD',
    ];

    private ?ImportService $service = null;

    private function service(): ImportService
    {
        return $this->service ??= ImportServiceFactory::fromDi();
    }

    // -------------------------------------------------------------------------
    // Screen
    // -------------------------------------------------------------------------

    protected function listAction(): HtmlResponse
    {
        $service = $this->service();
        $staging = $service->getStaging();
        $state   = $service->getPlanStore()->load();

        $vendorFiles = ImportServiceFactory::discoverVendorDefaults();

        $planView   = null;
        $staleError = null;
        if ($state !== null) {
            try {
                $source = ImportServiceFactory::sourceFromSpec($state['source'] ?? []);
                ['plan' => $plan] = $service->computePlan($source, $state['decisions'] ?? []);
                $planView = $this->buildPlanView($plan, $state);
            } catch (ImportStaleException | ImportSourceException $e) {
                $staleError = $e->getMessage();
            }
        }

        return $this->html([
            'planView'      => $planView,
            'state'         => $state,
            'staleError'    => $staleError,
            'lastResult'    => $state['last_result'] ?? null,
            'vendorClasses' => array_map([$this, 'shortName'], array_keys($vendorFiles)),
            'inbox'         => $staging->listInbox(),
            'entityOptions' => $this->entityOptions(),
            'jobThreshold'  => self::JOB_THRESHOLD,
        ]);
    }

    // -------------------------------------------------------------------------
    // Start a plan
    // -------------------------------------------------------------------------

    /** Plans the shipped vendor defaults (all importable entities that have one). */
    #[Fetch, HttpMethod('POST')]
    protected function startVendorAction(): FetchResponse
    {
        $files = ImportServiceFactory::discoverVendorDefaults();
        if ($files === []) {
            return $this->fetchError('Keine Vendor-Defaults gefunden');
        }

        return $this->startPlan(ImportServiceFactory::sourceSpec('vendor', 'Vendor-Defaults', $files));
    }

    /** Stages an inbox file and plans it against ONE chosen entity type. */
    #[Fetch, HttpMethod('POST')]
    protected function startInboxAction(): FetchResponse
    {
        $body   = DI::getRequest()->getJsonBody();
        $name   = trim((string) ($body['file'] ?? ''));
        $entity = trim((string) ($body['entity'] ?? ''));

        // The whitelist rule (§10): the class must be one the modules declared.
        if (!in_array($entity, DI::getModuleManager()->getImportEntities(), true)) {
            return $this->fetchError('Unbekannter Entity-Typ');
        }

        $staging = $this->service()->getStaging();
        try {
            $snapshot = $staging->stageInboxFile($name);
            $spec = ImportServiceFactory::sourceSpec(
                'snapshot',
                $this->shortName($entity) . ' aus ' . $name,
                [$entity => $staging->snapshotPath($snapshot)]
            );
            $spec['snapshot'] = $snapshot;
        } catch (ImportSourceException $e) {
            return $this->fetchError($e->getMessage());
        }

        return $this->startPlan($spec);
    }

    private function startPlan(array $spec): FetchResponse
    {
        $service = $this->service();
        try {
            $source = ImportServiceFactory::sourceFromSpec($spec);
            ['fingerprints' => $fingerprints] = $service->computePlan($source);
        } catch (ImportStaleException | ImportSourceException $e) {
            return $this->fetchError($e->getMessage());
        }

        $service->getPlanStore()->save([
            'created_at'   => date(DATE_ATOM),
            'source'       => $spec,
            'decisions'    => [],
            'fingerprints' => $fingerprints,
        ]);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    // -------------------------------------------------------------------------
    // Decisions
    // -------------------------------------------------------------------------

    /** Records ONE decision (accept/reject/pending, assignment, force-new) and replans. */
    #[Fetch, HttpMethod('POST')]
    protected function decideAction(): FetchResponse
    {
        $body     = DI::getRequest()->getJsonBody();
        $key      = trim((string) ($body['key'] ?? ''));
        $decision = trim((string) ($body['decision'] ?? ''));

        $state = $this->service()->getPlanStore()->load();
        if ($state === null) {
            return $this->fetchError('Kein aktueller Plan');
        }
        if (!$this->isValidEntryKey($key)) {
            return $this->fetchError('Ungültiger Eintrag');
        }
        if (!in_array($decision, [ImportPlanEntry::DECISION_ACCEPT, ImportPlanEntry::DECISION_REJECT, ImportPlanEntry::DECISION_PENDING], true)) {
            return $this->fetchError('Ungültige Entscheidung');
        }

        $entry = ['decision' => $decision];
        $targetId = (int) ($body['target_id'] ?? 0);
        if ($targetId > 0) {
            $entry['target_id'] = $targetId;
        }
        if ((bool) ($body['force_new'] ?? false)) {
            $entry['force_new'] = true;
        }

        if ($decision === ImportPlanEntry::DECISION_PENDING && count($entry) === 1) {
            unset($state['decisions'][$key]);   // full reset for this record
        } else {
            $state['decisions'][$key] = $entry;
        }
        $this->service()->getPlanStore()->save($state);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    /** Bulk decision for a whole DISPLAY group (the per-group «alle markieren» button). */
    #[Fetch, HttpMethod('POST')]
    protected function bulkAction(): FetchResponse
    {
        $body     = DI::getRequest()->getJsonBody();
        $group    = trim((string) ($body['group'] ?? ''));
        $decision = trim((string) ($body['decision'] ?? ''));

        // Only the two harmless-by-nature groups may be decided wholesale: new
        // records and the key backfill. A content difference is looked at one by
        // one — that is the whole point of the `changed` opt-in (ADR-032 §8).
        if (!in_array($group, ['new', 'changed-key'], true)) {
            return $this->fetchError('Sammel-Entscheidung nur für «Neu» und «Kennung nachtragen»');
        }
        if (!in_array($decision, [ImportPlanEntry::DECISION_ACCEPT, ImportPlanEntry::DECISION_REJECT], true)) {
            return $this->fetchError('Ungültige Entscheidung');
        }

        $service = $this->service();
        $state   = $service->getPlanStore()->load();
        if ($state === null) {
            return $this->fetchError('Kein aktueller Plan');
        }

        try {
            $source = ImportServiceFactory::sourceFromSpec($state['source'] ?? []);
            ['plan' => $plan] = $service->computePlan($source, $state['decisions'] ?? []);
        } catch (ImportStaleException | ImportSourceException $e) {
            return $this->fetchError($e->getMessage());
        }

        foreach ($plan->entries as $entry) {
            if ($this->groupOf($entry) !== $group) {
                continue;
            }
            $key = $entry->entityClass . '#' . $entry->sourceIndex;
            $state['decisions'][$key] = ($state['decisions'][$key] ?? []);
            $state['decisions'][$key]['decision'] = $decision;
        }
        $service->getPlanStore()->save($state);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    // -------------------------------------------------------------------------
    // Apply / discard
    // -------------------------------------------------------------------------

    /** Applies in-request below the threshold, queues the `import-apply` job above it. */
    #[Fetch, HttpMethod('POST')]
    protected function applyAction(): FetchResponse
    {
        $service = $this->service();
        $state   = $service->getPlanStore()->load();
        if ($state === null) {
            return $this->fetchError('Kein aktueller Plan');
        }

        try {
            $source = ImportServiceFactory::sourceFromSpec($state['source'] ?? []);
            ['plan' => $plan] = $service->computePlan($source, $state['decisions'] ?? []);
        } catch (ImportStaleException | ImportSourceException $e) {
            return $this->fetchError($e->getMessage());
        }

        $accepted = count(array_filter(
            $plan->entries,
            static fn(ImportPlanEntry $e) => $e->decision === ImportPlanEntry::DECISION_ACCEPT
                && in_array($e->outcome, [ImportOutcome::NewRecord, ImportOutcome::Changed], true)
        ));
        if ($accepted === 0) {
            return $this->fetchError('Nichts zur Übernahme markiert');
        }

        if ($accepted > self::JOB_THRESHOLD) {
            $queue = new JobQueue(DI::getInstance()->get('UnifiedEntityManager'));
            if ($queue->hasOpenEntry('import-apply')) {
                return $this->fetchError('Der Import-Job wartet bereits oder läuft gerade');
            }
            $queue->enqueue('import-apply', [], $this->actorName());
            $this->messageService->pushFlashAfterRedirect(
                'success',
                "{$accepted} Einträge — als Job «import-apply» eingereiht (Bildschirm Service → Jobs)"
            );
            return $this->fetch()->setStatus('success')->addCommand('reload');
        }

        try {
            $result = $service->apply($source, $state['decisions'] ?? [], $state['fingerprints'] ?? []);
        } catch (ImportStaleException $e) {
            return $this->fetchError($e->getMessage());
        }

        $state['last_result'] = [
            'at'      => date(DATE_ATOM),
            'via'     => 'request',
            'applied' => $result->countApplied(),
            'failed'  => $result->countFailed(),
            'lines'   => array_map(
                static fn(array $line) => [
                    'entry'   => $line['entry']->describe(),
                    'status'  => $line['status'],
                    'message' => $line['message'],
                ],
                $result->getLines()
            ),
        ];
        // The applied state changed the target data — refresh the fingerprints
        // so the surviving plan (applied records now `skipped`) stays actionable.
        $state['fingerprints'] = $service->fingerprints(
            $service->loadTargets(array_keys($state['source']['files'] ?? []))
        );
        $service->getPlanStore()->save($state);

        $this->messageService->pushFlashAfterRedirect(
            $result->countFailed() === 0 ? 'success' : 'error',
            "Import: {$result->countApplied()} übernommen, {$result->countFailed()} fehlgeschlagen"
        );
        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    /** Drops the current plan (and its snapshot, when the source was one). */
    #[Fetch, HttpMethod('POST')]
    protected function discardAction(): FetchResponse
    {
        $service = $this->service();
        $state   = $service->getPlanStore()->load();

        if ($state !== null && ($state['source']['type'] ?? '') === 'snapshot') {
            $snapshot = (string) ($state['source']['snapshot'] ?? '');
            if ($snapshot !== '') {
                $service->getStaging()->discard($snapshot);
            }
        }
        $service->getPlanStore()->clear();

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    // -------------------------------------------------------------------------
    // Display building (the template renders arrays only)
    // -------------------------------------------------------------------------

    private function buildPlanView(ImportPlan $plan, array $state): array
    {
        $service = $this->service();

        // Unclaimed targets per class — the assignment picker for unclear rows.
        $claimed = [];
        foreach ($plan->entries as $entry) {
            if ($entry->targetId !== null) {
                $claimed[$entry->entityClass][$entry->targetId] = true;
            }
        }
        $targetOptions = [];
        foreach ($service->loadTargets($plan->classOrder) as $class => $records) {
            foreach ($records as $record) {
                $row = $record->mapToArray();
                $id  = $row['id'] ?? null;
                if ($id === null || isset($claimed[$class][$id])) {
                    continue;
                }
                $targetOptions[$class][] = ['id' => $id, 'label' => $this->recordLabel($row) . ' (#' . $id . ')'];
            }
        }

        // Bucket every entry into its DISPLAY group (see self::GROUPS): the
        // planner's `changed` splits by meaning — key-only backfill vs. real
        // content difference.
        $buckets = [];
        foreach ($plan->entries as $entry) {
            $buckets[$this->groupOf($entry)][] = $entry;
        }

        $groups = [];
        foreach (self::GROUPS as $groupKey => $meta) {
            if (empty($buckets[$groupKey])) {
                continue;
            }
            $rows = [];
            foreach ($buckets[$groupKey] as $entry) {
                $rows[] = $this->buildRow($entry, $plan, $targetOptions, $groupKey);
            }
            $groups[] = [
                'key'      => $groupKey,
                'label'    => $meta['label'],
                'hint'     => $meta['hint'],
                'rows'     => $rows,
                'bulkable' => in_array($groupKey, ['new', 'changed-key'], true),
            ];
        }

        $acceptedCount = count(array_filter(
            $plan->entries,
            static fn(ImportPlanEntry $e) => $e->decision === ImportPlanEntry::DECISION_ACCEPT
                && in_array($e->outcome, [ImportOutcome::NewRecord, ImportOutcome::Changed], true)
        ));

        return [
            'sourceLabel'   => $plan->sourceLabel,
            'createdAt'     => (string) ($state['created_at'] ?? ''),
            'summary'       => $plan->summary(),
            'groups'        => $groups,
            'acceptedCount' => $acceptedCount,
        ];
    }

    private function buildRow(ImportPlanEntry $entry, ImportPlan $plan, array $targetOptions, string $groupKey): array
    {
        $diff = [];
        foreach ($entry->diff as $field => $values) {
            $diff[] = [
                'field'  => self::FIELD_LABELS[$field] ?? $field,
                'source' => $this->valueLabel($values['source'] ?? null),
                'target' => $this->valueLabel($values['target'] ?? null),
            ];
        }

        $suggestionLabel = null;
        if ($entry->suggestionId !== null) {
            foreach ($targetOptions[$entry->entityClass] ?? [] as $option) {
                if ($option['id'] === $entry->suggestionId) {
                    $suggestionLabel = $option['label'];
                }
            }
            $suggestionLabel ??= '#' . $entry->suggestionId;
        }

        $blockedBy = $entry->blockedByIndex !== null
            ? $this->contextLabel($plan->entries[$entry->blockedByIndex], $plan)
            : null;

        return [
            'key'          => $entry->entityClass . '#' . $entry->sourceIndex,
            'entity'       => $this->shortName($entry->entityClass),
            'label'        => $this->contextLabel($entry, $plan),
            'outcome'      => $entry->outcome->value,
            'group'        => $groupKey,
            'consequence'  => $this->consequenceText($entry, $groupKey, $suggestionLabel, $blockedBy),
            'reasonRaw'    => $entry->reason,
            'decision'     => $entry->decision,
            'targetId'     => $entry->targetId,
            'diff'         => $groupKey === 'changed-key' ? [] : $diff,   // key-only: the sentence says it all
            'suggestionId' => $entry->suggestionId,
            'suggestion'   => $suggestionLabel,
            'targets'      => $entry->outcome === ImportOutcome::Unclear
                ? ($targetOptions[$entry->entityClass] ?? [])
                : [],
        ];
    }

    /**
     * Which DISPLAY group an entry belongs to. Only `changed` splits: a diff that
     * touches nothing but `key` is the identity backfill (nothing visible
     * changes), everything else is a real content difference.
     */
    private function groupOf(ImportPlanEntry $entry): string
    {
        if ($entry->outcome !== ImportOutcome::Changed) {
            return $entry->outcome->value;
        }
        return array_keys($entry->diff) === ['key'] ? 'changed-key' : 'changed-content';
    }

    /**
     * The record's name WITH its position, so two entries called «Navigation»
     * are tellable apart («Stammdaten › Navigation»). Walks the source-side
     * parent chain through the plan itself — the source ids are only meaningful
     * inside the source, which is exactly what the chain is built from.
     */
    private function contextLabel(ImportPlanEntry $entry, ImportPlan $plan): string
    {
        $name = $this->recordLabel($entry->record);

        $byId = [];
        foreach ($plan->entries as $other) {
            if ($other->entityClass === $entry->entityClass && ($other->record['id'] ?? null) !== null) {
                $byId[$other->record['id']] = $other->record;
            }
        }

        $chain   = [];
        $current = $entry->record;
        $guard   = 0;
        while (($parentId = $current['parent_id'] ?? null) !== null && $guard++ < 10) {
            $parent = $byId[$parentId] ?? null;
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $this->recordLabel($parent));
            $current = $parent;
        }

        return $chain === [] ? $name : implode(' › ', $chain) . ' › ' . $name;
    }

    /**
     * What happens if this row is accepted, in plain German. Says the
     * CONSEQUENCE, never the field list — «weicht ab in: key» is meaningless
     * six months later, «bekommt die Kennung navigation» is not.
     */
    private function consequenceText(ImportPlanEntry $entry, string $groupKey, ?string $suggestionLabel, ?string $blockedBy): string
    {
        return match ($groupKey) {
            'changed-key' => 'bekommt die Kennung «' . ($entry->record['key'] ?? '?') . '»',
            'changed-content' => 'überschreibt bei dir: ' . implode(', ', array_map(
                fn(string $f) => self::FIELD_LABELS[$f] ?? $f,
                array_keys($entry->diff)
            )),
            'new'     => 'wird neu angelegt',
            'unclear' => $suggestionLabel !== null
                ? 'ist vermutlich dein ' . $suggestionLabel
                : 'kein ähnlicher Eintrag gefunden — zuordnen oder neu anlegen',
            'blocked' => $blockedBy !== null
                ? 'wird angelegt, sobald «' . $blockedBy . '» entschieden ist'
                : 'wartet auf einen anderen Eintrag',
            'invalid' => $entry->reason,
            default   => 'unverändert',
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<class-string, string> importable classes → display name */
    private function entityOptions(): array
    {
        $options = [];
        foreach (DI::getModuleManager()->getImportEntities() as $class) {
            $options[$class] = $this->shortName($class);
        }
        return $options;
    }

    private function shortName(string $class): string
    {
        return (new \ReflectionClass($class))->getShortName();
    }

    private function recordLabel(array $record): string
    {
        $label = $record['name'] ?? $record['path'] ?? $record['title'] ?? null;
        $label = is_string($label) && $label !== '' ? $label : '#' . ($record['id'] ?? '?');

        // A ref entry carries no routing of its own — say so, otherwise
        // «Navigation › Navigation» reads like a duplicate instead of a pointer.
        if (($record['ref'] ?? null) !== null) {
            $label .= ' (Verweis)';
        }
        // MetaData: title alone is ambiguous across languages.
        if (isset($record['language']) && is_string($record['language']) && $record['language'] !== '') {
            $label .= ' [' . $record['language'] . ']';
        }
        return $label;
    }

    private function valueLabel(mixed $value): string
    {
        if ($value === null) return '—';
        if (is_bool($value)) return $value ? 'ja' : 'nein';
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (string) $value;
    }

    /** `{whitelisted class}#{int}` — nothing else reaches the decisions map. */
    private function isValidEntryKey(string $key): bool
    {
        $pos = strrpos($key, '#');
        if ($pos === false) {
            return false;
        }
        $class = substr($key, 0, $pos);
        $index = substr($key, $pos + 1);

        return ctype_digit($index)
            && in_array($class, DI::getModuleManager()->getImportEntities(), true);
    }

    /** Who queued/applied, for job `createdBy` (same as JobController). */
    private function actorName(): string
    {
        $name = trim(DI::getAuthService()->getCurrentUser()->getUserName());
        return $name !== '' ? $name : 'backend';
    }
}
