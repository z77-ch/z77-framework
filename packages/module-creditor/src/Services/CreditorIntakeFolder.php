<?php

namespace Z77\Module\Creditor\Services;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Module\Creditor\Repositories\CreditorIntakeRepository;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The intake FOLDER of the Kreditorenjournal (creditor plan §3 «Folder channel», owner answer
 * §11.7) — what the job `creditor-intake` works, and what the tab «Nicht eingelesen» acts on.
 *
 * The folder: `creditorConfig['intake']['folder']`, default {@see DEFAULT_FOLDER} — relative to the
 * project root, i.e. `shared/data/…` in the release layout (stable across releases, outside
 * `public/`). The owner's upload script (a Windows PC, SFTP as the PHP user) writes
 * `<name>.pdf.part` and renames it to `<name>.pdf` there; it never creates the folder — this class
 * does ({@see ensure()}: the folder, `.work/`, `failed/`), on every job pass and when the tab
 * «Nicht eingelesen» is opened.
 *
 * One pass ({@see pass()}):
 *
 *   1. a file left in `.work/` by a crashed pass is taken up again first (idempotent: its hash is
 *      either stored already — then it is only verified and deleted — or not — then it is read);
 *   2. the folder's REGULAR files (no directory, no symlink — nothing is followed; dotfiles and
 *      `*.part` ignored): a `*.pdf` (any case) unchanged for {@see MIN_AGE_SECONDS} is CLAIMED by
 *      `rename()` into `.work/` (atomic — a second runner cannot take it too), then
 *      `CreditorIntakeService::receive()` (channel `folder` — the invoice is written «Erkennung
 *      ausstehend»; the job recognises it after the folder, the same path as an upload's);
 *      - received → the DMS document's checksum is compared with the file's SHA-256, and only then
 *        the file is DELETED (no `processed/` — the DMS holds the record, owner answer 7);
 *      - the identical file read before (hash) → verified the same way and deleted, noted «bereits
 *        eingelesen» in the job's line (no row);
 *      - refused (empty, too large, not a PDF by its bytes) → moved to `failed/` with an intake row
 *        `failed` carrying the reason — it stays until the owner acts;
 *      - anything else (the database away) → left in `.work/`, the next pass tries again;
 *   3. any other file unchanged for {@see NON_PDF_AGE_SECONDS} → `failed/` («kein PDF»).
 *
 * The tab acts per row ({@see retry()}, {@see remove()}) or on the ticked rows at once
 * ({@see removeSelected()}, owner 2026-11-16).
 *
 * File names are only ever used INSIDE the intake folder (base names from `scandir()`, a name with a
 * separator or a NUL is skipped); the intake row keeps the name for display, the DMS stores a slug.
 */
final class CreditorIntakeFolder
{
    public const DEFAULT_FOLDER       = 'data/framework/creditor/intake';
    public const WORK                 = '.work';
    public const FAILED               = 'failed';
    public const MIN_AGE_SECONDS      = 60;
    public const NON_PDF_AGE_SECONDS  = 3600;
    public const REASON_NOT_PDF       = 'kein PDF';

    private readonly string $dir;

    /**
     * @param string $dir   the intake folder, absolute
     * @param string $actor who receives (the job: `cron:creditor-intake`; the screen: the user)
     */
    public function __construct(
        private readonly UnifiedEntityManager $em,
        string $dir,
        private readonly string $actor,
        private readonly ?DocumentService $documents = null,
    ) {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
    }

    /** The installation's folder (`creditorConfig['intake']['folder']`, relative = to the project root). */
    public static function forInstallation(UnifiedEntityManager $em, string $actor): self
    {
        return new self($em, self::configuredDir(), $actor);
    }

    /** The ONE place that reads `creditorConfig['intake']['folder']`. */
    public static function configuredDir(): string
    {
        try {
            $folder = DI::getModuleManager()->getModuleConfig('creditor')?->get('intake', [])['folder'] ?? '';
        } catch (\Throwable) {
            $folder = '';
        }
        $folder = is_string($folder) && trim($folder) !== '' ? trim(str_replace('\\', '/', $folder)) : self::DEFAULT_FOLDER;
        if (str_starts_with($folder, '/') || preg_match('#^[A-Za-z]:/#', $folder)) {
            return $folder;
        }

        return rtrim(str_replace('\\', '/', (string) (defined('ABS_BASE_PATH') ? ABS_BASE_PATH : getcwd())), '/') . '/' . $folder;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Creates the folder, `.work/` and `failed/` when missing (the upload script never creates
     * them). Null = ready; else a German reason.
     */
    public function ensure(): ?string
    {
        foreach ([$this->dir, $this->dir . '/' . self::WORK, $this->dir . '/' . self::FAILED] as $path) {
            if (is_link($path)) {
                return 'Eingangsordner: «' . basename($path) . '» ist ein Verweis — nicht verwendet';
            }
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                return 'Eingangsordner kann nicht angelegt werden: ' . $path;
            }
        }

        return null;
    }

    /**
     * One pass over the folder.
     *
     * @param callable(): bool $hasTimeLeft asked between files
     * @return array{received: int, duplicates: int, failed: int, waiting: int, kept: int, more: bool, notes: list<string>}
     */
    public function pass(callable $hasTimeLeft, ?int $now = null): array
    {
        $now    = $now ?? time();
        $result = ['received' => 0, 'duplicates' => 0, 'failed' => 0, 'waiting' => 0, 'kept' => 0, 'more' => false, 'notes' => []];
        $problem = $this->ensure();
        if ($problem !== null) {
            $result['notes'][] = $problem;

            return $result;
        }

        // 1. leftovers of a crashed pass
        foreach ($this->regularFiles($this->dir . '/' . self::WORK) as $name) {
            if (!$hasTimeLeft()) {
                $result['more'] = true;

                return $result;
            }
            $this->process($name, $result);
        }

        // 2. + 3. the folder itself
        foreach ($this->regularFiles($this->dir) as $name) {
            if (str_ends_with(strtolower($name), '.part')) {
                continue;
            }
            $path = $this->dir . '/' . $name;
            $age  = $now - (int) @filemtime($path);
            $pdf  = str_ends_with(strtolower($name), '.pdf');
            if ($age < ($pdf ? self::MIN_AGE_SECONDS : self::NON_PDF_AGE_SECONDS)) {
                $result['waiting']++;
                continue;
            }
            if (!$hasTimeLeft()) {
                $result['more'] = true;

                return $result;
            }
            if (!$pdf) {
                $this->fail($path, $name, self::REASON_NOT_PDF, $result);
                continue;
            }
            $claimed = $this->freeName($this->dir . '/' . self::WORK, $name);
            if (!@rename($path, $this->dir . '/' . self::WORK . '/' . $claimed)) {
                continue;   // taken by another pass, or gone — nothing to do
            }
            $this->process($claimed, $result);
        }

        return $result;
    }

    /**
     * «Erneut versuchen» (tab «Nicht eingelesen»): the file goes back from `failed/` into the folder (a name
     * taken there gets « (2)»), its row is removed — the next pass reads it again.
     *
     * @return string the name it has in the folder now
     * @throws CreditorRefusedException CHANGED — no such failed row; the file is missing
     */
    public function retry(int $intakeId): string
    {
        $intake = $this->failedRow($intakeId);
        $source = $this->failedPath($intake);
        if ($source === null || $this->ensure() !== null) {
            throw new CreditorRefusedException(CreditorRefusedException::CHANGED, 'Die Datei liegt nicht mehr im Ordner «failed» — «Entfernen» räumt den Eintrag weg.');
        }
        $target = $this->freeName($this->dir, (string) $intake->getFailedName());
        if (!@rename($source, $this->dir . '/' . $target)) {
            throw new \RuntimeException('creditor intake: could not move ' . $intake->getFailedName() . ' back from failed/');
        }
        @touch($this->dir . '/' . $target, time() - self::MIN_AGE_SECONDS);   // ready for the next pass at once
        $this->removeRow($intakeId);

        return $target;
    }

    /**
     * «Entfernen» (tab «Nicht eingelesen»): the file in `failed/` is deleted and its row removed.
     *
     * @throws CreditorRefusedException CHANGED — no such failed row
     */
    public function remove(int $intakeId): void
    {
        $intake = $this->failedRow($intakeId);
        $path   = $this->failedPath($intake);
        if ($path !== null && !@unlink($path)) {
            throw new \RuntimeException('creditor intake: could not delete failed/' . $intake->getFailedName());
        }
        $this->removeRow($intakeId);
    }

    /**
     * «Entfernen …» for the rows ticked in the tab «Nicht eingelesen» (owner 2026-11-16: «nicht
     * eingelesen sollten auch alle gelöscht werden können»): each through {@see remove()}, in id
     * order. A row gone since (removed or retried in another request) is skipped and comes back
     * in `refused`, as is a file that cannot be deleted; the others are removed. A row whose file
     * was already missing from `failed/` is removed like the single «Entfernen» does it.
     *
     * @param list<int> $intakeIds
     * @return array{removed: list<string>, refused: list<string>} the names removed; German, one per row not removed
     */
    public function removeSelected(array $intakeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $intakeIds), static fn(int $id): bool => $id > 0)));
        sort($ids);
        $removed = [];
        $refused = [];
        foreach ($ids as $id) {
            $row  = $this->intakes()->find($id);
            $name = $row instanceof CreditorIntake ? $row->getOriginalName() : 'Eintrag ' . $id;
            try {
                $this->remove($id);
                $removed[] = $name;
            } catch (CreditorRefusedException) {
                $refused[] = $name . ': schon weg';
            } catch (\RuntimeException $e) {
                error_log($e->getMessage());
                $refused[] = $name . ': die Datei liess sich nicht löschen';
            }
        }

        return ['removed' => $removed, 'refused' => $refused];
    }

    /** Whether the failed row's file is still in `failed/` (the tab says so otherwise). */
    public function failedFileExists(CreditorIntake $intake): bool
    {
        return $this->failedPath($intake) !== null;
    }

    // ── one file ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $result */
    private function process(string $name, array &$result): void
    {
        $path  = $this->dir . '/' . self::WORK . '/' . $name;
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            $result['kept']++;
            $result['notes'][] = $name . ': nicht lesbar — bleibt in .work';

            return;
        }
        $hash = hash('sha256', $bytes);
        try {
            $invoice = (new CreditorIntakeService($this->em, $this->documents, $this->actor))->receive($bytes, $name, CreditorIntake::CHANNEL_FOLDER);
        } catch (CreditorRefusedException $e) {
            if ($e->reason === CreditorRefusedException::DUPLICATE_FILE) {
                $this->duplicate($path, $name, $hash, $e->getMessage(), $result);
            } else {
                $this->fail($path, $name, $e->getMessage(), $result);
            }

            return;
        } catch (\Throwable $e) {
            error_log('creditor intake folder: ' . $name . ' stays in .work — ' . get_class($e) . ': ' . $e->getMessage());
            $result['kept']++;
            $result['notes'][] = $name . ': nicht eingelesen (' . get_class($e) . ') — nächster Lauf';

            return;
        }
        if ($this->deleteVerified($path, $name, $invoice->getDocumentId(), $hash, $result)) {
            $result['received']++;
        }
    }

    /**
     * The identical file was read before — after a crash between the commit and the delete, or
     * sent twice. Verified against the stored PDF, then deleted. Its invoice's recognition is not
     * this class's business: the intake wrote it pending in the same unit of work, the job works it.
     *
     * @param array<string, mixed> $result
     */
    private function duplicate(string $path, string $name, string $hash, string $message, array &$result): void
    {
        $known      = $this->intakes()->findBySha256($hash);
        $documentId = $known?->getDocumentId();
        if ($documentId !== null ? $this->deleteVerified($path, $name, $documentId, $hash, $result) : @unlink($path)) {
            $result['duplicates']++;
            $result['notes'][] = $name . ': ' . $message;
        }
    }

    /**
     * Deletes the intake copy once the DMS holds the SAME bytes (checksum = the file's SHA-256).
     * A mismatch keeps the file in `.work/` and says so — nothing is lost.
     *
     * @param array<string, mixed> $result
     */
    private function deleteVerified(string $path, string $name, int $documentId, string $hash, array &$result): bool
    {
        $document = ($this->documents ?? DocumentService::create())->get($documentId);
        if ($document === null || !hash_equals($hash, strtolower($document->getChecksum()))) {
            error_log('creditor intake folder: ' . $name . ' kept — DMS document ' . $documentId . ' does not hold its bytes');
            $result['kept']++;
            $result['notes'][] = $name . ': Ablage im DMS nicht bestätigt — bleibt in .work';

            return false;
        }
        if (!@unlink($path)) {
            $result['kept']++;
            $result['notes'][] = $name . ': eingelesen, aber nicht löschbar — bleibt in .work';

            return false;
        }

        return true;
    }

    /**
     * Refused: the file into `failed/` (a free name), an intake row `failed` with the reason.
     *
     * @param array<string, mixed> $result
     */
    private function fail(string $path, string $name, string $reason, array &$result): void
    {
        $target = $this->freeName($this->dir . '/' . self::FAILED, $name);
        $size   = (int) @filesize($path);
        if (!@rename($path, $this->dir . '/' . self::FAILED . '/' . $target)) {
            $result['kept']++;
            $result['notes'][] = $name . ': nicht nach failed verschoben';

            return;
        }
        $this->em->getTransaction(CreditorIntake::class)->run(function () use ($name, $size, $reason, $target): void {
            $this->em->persist(CreditorIntake::failed($name, $size, $reason, $target, $this->actor, new \DateTimeImmutable()));
        });
        $result['failed']++;
        $result['notes'][] = $name . ': ' . $reason . ' — in failed';
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<string> regular files of $dir (no dotfile, no directory, no symlink), by name */
    private function regularFiles(string $dir): array
    {
        $names = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '' || $name[0] === '.' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $names[] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** $name, or «<name> (2).<ext>», «(3)» … — the first that is free in $dir. */
    private function freeName(string $dir, string $name): string
    {
        $dot  = strrpos($name, '.');
        $stem = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
        $ext  = $dot === false || $dot === 0 ? '' : substr($name, $dot);
        for ($i = 1, $candidate = $name; file_exists($dir . '/' . $candidate) || is_link($dir . '/' . $candidate); $i++) {
            $candidate = $stem . ' (' . ($i + 1) . ')' . $ext;
        }

        return $candidate;
    }

    private function failedRow(int $intakeId): CreditorIntake
    {
        $intake = $intakeId > 0 ? $this->intakes()->find($intakeId) : null;
        if (!$intake instanceof CreditorIntake || !$intake->isFailed() || $intake->getFailedName() === null) {
            throw new CreditorRefusedException(CreditorRefusedException::CHANGED, 'Diesen Eintrag gibt es nicht mehr — die Liste neu laden.');
        }

        return $intake;
    }

    /** The row's file in `failed/` — only a plain base name, a regular file, no symlink; null = gone. */
    private function failedPath(CreditorIntake $intake): ?string
    {
        $name = (string) $intake->getFailedName();
        if ($name === '' || $name !== basename($name) || str_contains($name, '\\') || $name[0] === '.') {
            return null;
        }
        $path = $this->dir . '/' . self::FAILED . '/' . $name;

        return !is_link($path) && is_file($path) ? $path : null;
    }

    private function removeRow(int $intakeId): void
    {
        $this->em->getTransaction(CreditorIntake::class)->run(function () use ($intakeId): void {
            $row = $this->intakes()->find($intakeId);
            if ($row instanceof CreditorIntake && $row->isFailed()) {
                $this->em->remove($row);
            }
        });
    }

    private function intakes(): CreditorIntakeRepository
    {
        return $this->em->getRepository(CreditorIntake::class);
    }
}
