<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see FiscalYearCloseLog} — the protocol of
 * closing and reopening fiscal years (owner 2026-09-30). Two reads: the
 * fiscal-year list shows the latest rows per year ({@see byYear()}), and the
 * protocol «Abschlüsse und MWST» (Finanzen › Änderungsprotokoll, second tab,
 * owner 2026-11-25) shows them together with the VAT return protocol
 * ({@see protocolPage()}).
 */
class FiscalYearCloseLogRepository extends DoctrineRepository
{
    /**
     * The VAT return protocol's actions this screen shows — a post and a lift. Any other action a
     * row may hold (the `filed` / `unfiled` rows a development database kept from before
     * 2026-11-25) is not listed.
     */
    private const VAT_ACTIONS = ['post', 'lift'];

    /**
     * The protocol of the given years, newest first, grouped by year id. A
     * year collects a handful of rows over its life (each close and reopen
     * is one), so every row of the listed years is read — no per-year
     * limit in SQL. Doctrine-only (DQL: `findBy()` has no ORDER BY).
     *
     * @param list<int> $yearIds
     * @return array<int, list<FiscalYearCloseLog>> year id → rows, newest first
     */
    public function byYear(array $yearIds): array
    {
        if ($yearIds === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()
            ->select('l')
            ->from(FiscalYearCloseLog::class, 'l')
            ->where('l.fiscalYearId IN (:years)')
            ->setParameter('years', array_values($yearIds))
            ->orderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
        $byYear = [];
        foreach ($rows as $row) {
            $byYear[$row->getFiscalYearId()][] = $row;
        }

        return $byYear;
    }

    /**
     * One page of the protocol «Abschlüsse und MWST»: the close log and the VAT return log as ONE
     * list — a `UNION ALL` of the two tables in SQL, no table of its own (each writer keeps its
     * protocol; this read only joins them for the eye). Every value is bound, the order is fixed
     * (the event's time, then the row), never taken from input. Doctrine-only (SQL).
     *
     * @return list<CloseProtocolEntry>
     */
    public function protocolPage(CloseProtocolSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = self::protocolWhere($search);
        $dir  = $search->descending ? 'DESC' : 'ASC';
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT p.* FROM (' . self::protocolUnion() . ") p{$where} ORDER BY p.acted_at {$dir}, p.source {$dir}, p.id {$dir} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        );

        return array_map(self::protocolEntry(...), $rows);
    }

    /** How many protocol rows match $search — the pager and the badge. Doctrine-only (SQL). */
    public function protocolCount(CloseProtocolSearch $search): int
    {
        [$where, $params, $types] = self::protocolWhere($search);

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM (' . self::protocolUnion() . ") p{$where}", $params, $types);
    }

    /**
     * The two protocols in one shape. `source` orders two events of the same second (`vat` after
     * `year` when newest first); `subject_from` / `subject_to` are what a row is ABOUT — the VAT
     * period, or the fiscal year (read from `fiscal_year`; a deleted year has none and is found by
     * the other criteria only). The VAT actions are literals of this class.
     */
    private static function protocolUnion(): string
    {
        $vatActions = implode(', ', array_map(static fn(string $a): string => "'{$a}'", self::VAT_ACTIONS));

        return "SELECT 'year' AS source, l.id, l.acted_at, l.actor,"
            . " CASE WHEN l.action = 'close' THEN 'year-closed' ELSE 'year-reopened' END AS kind,"
            . ' l.fiscal_year_id AS year_id, l.fiscal_year_code AS year_code,'
            . ' NULL AS period_from, NULL AS period_to, NULL AS revision, NULL AS vat_return_id,'
            . ' l.reason, l.confirmed_warnings AS warnings, NULL AS detail,'
            . ' y.start_date AS subject_from, y.end_date AS subject_to'
            . ' FROM fiscal_year_close_log l LEFT JOIN fiscal_year y ON y.id = l.fiscal_year_id'
            . " UNION ALL SELECT 'vat' AS source, v.id, v.acted_at, v.actor,"
            . " CASE WHEN v.action = 'post' THEN 'vat-posted' ELSE 'vat-lifted' END AS kind,"
            . ' NULL, NULL,'
            . ' v.period_from, v.period_to, v.revision, v.vat_return_id,'
            . ' v.reason, NULL, v.detail,'
            . ' v.period_from, v.period_to'
            . " FROM vat_return_log v WHERE v.action IN ({$vatActions})";
    }

    /**
     * The WHERE over the union: fragments AND-ed, one bound value each. The year scope takes the
     * year's own close / reopen rows and the VAT rows of the periods that END in it (the VAT return
     * list's rule); the day range compares the timestamp half-open; the subject OVERLAPS the range.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private static function protocolWhere(CloseProtocolSearch $search): array
    {
        $where  = [];
        $params = [];
        $types  = [];
        $add = static function (string $sql, array $values, ParameterType ...$valueTypes) use (&$where, &$params, &$types): void {
            $where[] = $sql;
            foreach (array_values($values) as $i => $value) {
                $params[] = $value;
                $types[]  = $valueTypes[$i] ?? ParameterType::STRING;
            }
        };
        if ($search->fiscalYearId !== null) {
            $add("((p.source = 'year' AND p.year_id = ?) OR (p.source = 'vat' AND p.period_to BETWEEN ? AND ?))", [$search->fiscalYearId, $search->yearFrom, $search->yearTo], ParameterType::INTEGER);
        }
        if ($search->atFrom !== null) {
            $add('p.acted_at >= ?', [$search->atFrom . ' 00:00:00']);
        }
        if ($search->atTo !== null) {
            $add('p.acted_at < ?', [(new \DateTimeImmutable($search->atTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
        }
        if ($search->who !== null) {
            $add("p.actor LIKE ? ESCAPE '!'", ['%' . strtr($search->who, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%']);
        }
        if ($search->kinds !== null) {
            $add('p.kind IN (' . implode(', ', array_fill(0, count($search->kinds), '?')) . ')', array_values($search->kinds));
        }
        if ($search->subjectFrom !== null && $search->subjectTo !== null) {
            $add('p.subject_from <= ? AND p.subject_to >= ?', [$search->subjectTo, $search->subjectFrom]);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $types];
    }

    /** @param array<string, mixed> $row */
    private static function protocolEntry(array $row): CloseProtocolEntry
    {
        $isVat  = $row['source'] === 'vat';
        $detail = [];
        if ($isVat && $row['detail'] !== null && $row['detail'] !== '') {
            $decoded = json_decode((string) $row['detail'], true);
            $detail  = is_array($decoded) ? $decoded : [];
        }
        $warnings = $isVat
            ? array_values(array_filter(array_map('strval', is_array($detail['warnings'] ?? null) ? $detail['warnings'] : []), static fn(string $w): bool => $w !== ''))
            : ($row['warnings'] === null || $row['warnings'] === '' ? [] : explode("\n", (string) $row['warnings']));
        $day = static fn(mixed $v): ?\DateTimeImmutable => $v === null ? null : new \DateTimeImmutable(substr((string) $v, 0, 10));

        return new CloseProtocolEntry(
            (string) $row['kind'],
            (int) $row['id'],
            new \DateTimeImmutable((string) $row['acted_at']),
            (string) $row['actor'],
            $row['year_id'] === null ? null : (int) $row['year_id'],
            $row['year_code'] === null ? null : (string) $row['year_code'],
            $isVat ? $day($row['period_from']) : null,
            $isVat ? $day($row['period_to']) : null,
            $row['revision'] === null ? null : (int) $row['revision'],
            $row['vat_return_id'] === null ? null : (int) $row['vat_return_id'],
            $row['reason'] === null ? null : (string) $row['reason'],
            $warnings,
            $detail,
        );
    }
}
