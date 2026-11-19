<?php

/**
 * schema-reserved-words harness (CLI, offline) — no table, column, index or alias name of the
 * framework is a reserved word of a database server z77 may meet (PERSIST-RESERVED-001).
 *
 * Why it exists: `bank_statement.to_date` ran on MariaDB 10.6 (the maintainer's machine) and 11.4
 * (a production host) and broke a FRESH install on MariaDB 13 with error 1064 — MariaDB 12.3 made
 * TO_DATE a parser-level keyword. No DB harness can see that: they all run on the maintainer's
 * server. This one checks the names against keyword lists instead, without a database.
 *
 *   - (K) the embedded keyword list, by source and server version;
 *   - (O) the ORM mapping: every entity of packages/* (found by `#[ORM\Entity`), its metadata
 *     loaded through Doctrine's attribute driver — table, columns, join columns, join tables,
 *     indexes, unique constraints. A name is fine when it is no keyword or when the mapping
 *     quotes it with backticks;
 *   - (M) every migration in packages/* /res/migrations: the SQL of each `addSql()` / connection
 *     call, parsed for the names it DEFINES or names in DDL (CREATE / ALTER / RENAME / DROP TABLE,
 *     CREATE / DROP INDEX, INSERT INTO, UPDATE … SET) and for `AS` aliases — unquoted keyword =
 *     failure. An OLD migration that already ran stays valid by quoting the name, never by
 *     editing what it creates;
 *   - (R) raw SQL in packages/* /src and tests/: an `AS` alias that is a keyword.
 *
 * The keyword list is a COPY, deliberately: Doctrine DBAL 4.5's own lists (`Platforms/Keywords/
 * MariaDB123Keywords`, `MySQL84Keywords`, …) are deprecated and leave with DBAL 5, and a guard
 * must not change when a dependency does. When a server version adds a keyword, add it to
 * KEYWORDS with its source and version.
 *
 * Run: php tests/schema-reserved-words.php  (group `persistence`)
 * Needs vendor/ with Doctrine. Writes nothing.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "schema-reserved-words: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\Driver\ClassNames;
use Z77\Persistence\Doctrine\Type\MoneyType;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/**
 * Reserved words and keywords that cannot be an unquoted identifier — the union of the lists
 * below. Versions covered: MySQL 5.7, 8.0, 8.4; MariaDB 10.6 – 11.x, 11.7 (VECTOR), 12.3
 * (TO_DATE) and therefore 13 (no further keyword of 13 known — see the last group).
 * Source: Doctrine DBAL 4.5.0, `src/Platforms/Keywords/` (copied 2026-11-19), which cites
 * dev.mysql.com/doc/refman/8.0|8.4/en/keywords.html, the MariaDB reserved-words page and
 * MDEV-19683; MySQL's lists include a few non-reserved keywords (ADMIN, AUTO, LOG, …) — kept:
 * a name that needs no quote anywhere is the goal, not the minimum.
 */
const KEYWORDS = [
    // MySQL 5.7 / MariaDB (both) — DBAL MySQLKeywords ∩ MariaDBKeywords
    'ACCESSIBLE', 'ADD', 'ALL', 'ALTER', 'ANALYZE', 'AND', 'AS', 'ASC', 'ASENSITIVE', 'BEFORE', 'BETWEEN', 'BIGINT', 'BINARY',
    'BLOB', 'BOTH', 'BY', 'CALL', 'CASCADE', 'CASE', 'CHANGE', 'CHAR', 'CHARACTER', 'CHECK', 'COLLATE', 'COLUMN', 'CONDITION',
    'CONSTRAINT', 'CONTINUE', 'CONVERT', 'CREATE', 'CROSS', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER',
    'CURSOR', 'DATABASE', 'DATABASES', 'DAY_HOUR', 'DAY_MICROSECOND', 'DAY_MINUTE', 'DAY_SECOND', 'DEC', 'DECIMAL', 'DECLARE',
    'DEFAULT', 'DELAYED', 'DELETE', 'DESC', 'DESCRIBE', 'DETERMINISTIC', 'DISTINCT', 'DISTINCTROW', 'DIV', 'DOUBLE', 'DROP',
    'DUAL', 'EACH', 'ELSE', 'ELSEIF', 'ENCLOSED', 'ESCAPED', 'EXCEPT', 'EXISTS', 'EXIT', 'EXPLAIN', 'FALSE', 'FETCH', 'FLOAT',
    'FLOAT4', 'FLOAT8', 'FOR', 'FORCE', 'FOREIGN', 'FROM', 'FULLTEXT', 'GENERATED', 'GET', 'GRANT', 'GROUP', 'HAVING',
    'HIGH_PRIORITY', 'HOUR_MICROSECOND', 'HOUR_MINUTE', 'HOUR_SECOND', 'IF', 'IGNORE', 'IN', 'INDEX', 'INFILE', 'INNER',
    'INOUT', 'INSENSITIVE', 'INSERT', 'INT', 'INT1', 'INT2', 'INT3', 'INT4', 'INT8', 'INTEGER', 'INTERSECT', 'INTERVAL', 'INTO',
    'IO_AFTER_GTIDS', 'IO_BEFORE_GTIDS', 'IS', 'ITERATE', 'JOIN', 'KEY', 'KEYS', 'KILL', 'LEADING', 'LEAVE', 'LEFT', 'LIKE',
    'LIMIT', 'LINEAR', 'LINES', 'LOAD', 'LOCALTIME', 'LOCALTIMESTAMP', 'LOCK', 'LONG', 'LONGBLOB', 'LONGTEXT', 'LOOP',
    'LOW_PRIORITY', 'MASTER_BIND', 'MASTER_SSL_VERIFY_SERVER_CERT', 'MATCH', 'MAXVALUE', 'MEDIUMBLOB', 'MEDIUMINT',
    'MEDIUMTEXT', 'MIDDLEINT', 'MINUTE_MICROSECOND', 'MINUTE_SECOND', 'MOD', 'MODIFIES', 'NATURAL', 'NOT', 'NO_WRITE_TO_BINLOG',
    'NULL', 'NUMERIC', 'ON', 'OPTIMIZE', 'OPTIMIZER_COSTS', 'OPTION', 'OPTIONALLY', 'OR', 'ORDER', 'OUT', 'OUTER', 'OUTFILE',
    'OVER', 'PARTITION', 'PRECISION', 'PRIMARY', 'PROCEDURE', 'PURGE', 'RANGE', 'READ', 'READS', 'READ_WRITE', 'REAL',
    'RECURSIVE', 'REFERENCES', 'REGEXP', 'RELEASE', 'RENAME', 'REPEAT', 'REPLACE', 'REQUIRE', 'RESIGNAL', 'RESTRICT', 'RETURN',
    'REVOKE', 'RIGHT', 'RLIKE', 'ROWS', 'SCHEMA', 'SCHEMAS', 'SECOND_MICROSECOND', 'SELECT', 'SENSITIVE', 'SEPARATOR', 'SET',
    'SHOW', 'SIGNAL', 'SMALLINT', 'SPATIAL', 'SPECIFIC', 'SQL', 'SQLEXCEPTION', 'SQLSTATE', 'SQLWARNING', 'SQL_BIG_RESULT',
    'SQL_CALC_FOUND_ROWS', 'SQL_SMALL_RESULT', 'SSL', 'STARTING', 'STORED', 'STRAIGHT_JOIN', 'TABLE', 'TERMINATED', 'THEN',
    'TINYBLOB', 'TINYINT', 'TINYTEXT', 'TO', 'TRAILING', 'TRIGGER', 'TRUE', 'UNDO', 'UNION', 'UNIQUE', 'UNLOCK', 'UNSIGNED',
    'UPDATE', 'USAGE', 'USE', 'USING', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP', 'VALUES', 'VARBINARY', 'VARCHAR',
    'VARCHARACTER', 'VARYING', 'VIRTUAL', 'WHEN', 'WHERE', 'WHILE', 'WINDOW', 'WITH', 'WRITE', 'XOR', 'YEAR_MONTH', 'ZEROFILL',
    // MySQL 8.0 (new keywords and reserved words) — DBAL MySQL80Keywords
    'ADMIN', 'ARRAY', 'CUBE', 'CUME_DIST', 'DENSE_RANK', 'EMPTY', 'FIRST_VALUE', 'FUNCTION', 'GROUPING', 'GROUPS', 'JSON_TABLE',
    'LAG', 'LAST_VALUE', 'LATERAL', 'LEAD', 'MEMBER', 'NTH_VALUE', 'NTILE', 'OF', 'PERCENT_RANK', 'PERSIST', 'PERSIST_ONLY',
    'RANK', 'ROW', 'ROW_NUMBER', 'SYSTEM',
    // MySQL 8.4 (new keywords and reserved words) — DBAL MySQL84Keywords
    'AUTO', 'BERNOULLI', 'GTIDS', 'LOG', 'MANUAL', 'PARALLEL', 'PARSE_TREE', 'QUALIFY', 'S3', 'TABLESAMPLE',
    // MariaDB only (10.x / 11.x reserved words) — DBAL MariaDBKeywords
    'GENERAL', 'IGNORE_SERVER_IDS', 'MASTER_HEARTBEAT_PERIOD', 'OFFSET', 'RETURNING', 'SLOW',
    // MariaDB 11.7 — DBAL MariaDB117Keywords
    'VECTOR',
    // MariaDB 12.3 — DBAL MariaDB123Keywords, MDEV-19683: TO_DATE() as a parser-level keyword. Confirmed by
    // the owner's install test 2026-11-19: `to_date` works on 10.6.28 and 11.4.13, fails (1064) on 13.0.2.
    'TO_DATE',
    // MariaDB 13.0 — no further keyword known (DBAL 4.5 has no 13 list); add here with the source when one is.
];

/** Words after which a parenthesised list follows in a CREATE TABLE / ALTER TABLE item: not a column. */
const CONSTRAINT_HEADS = ['INDEX', 'KEY', 'UNIQUE', 'PRIMARY', 'CONSTRAINT', 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'CHECK', 'PERIOD'];

$keywords = array_fill_keys(KEYWORDS, true);
$isKeyword = static fn(string $name): bool => isset($keywords[strtoupper($name)]);
$root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));

// ── K. the list ─────────────────────────────────────────────────────────────

echo "K. Keyword list\n";
check('K1 the list covers MySQL 8.0 / 8.4 and MariaDB up to 12.3 / 13: TO_DATE (MariaDB 12.3), VECTOR (11.7), RANK and ROW (MySQL 8.0), QUALIFY (MySQL 8.4), OFFSET and RETURNING (MariaDB) are in it; no word twice',
    $isKeyword('to_date') && $isKeyword('VECTOR') && $isKeyword('rank') && $isKeyword('row') && $isKeyword('QUALIFY') && $isKeyword('offset') && $isKeyword('returning')
    && count(KEYWORDS) === count(array_unique(KEYWORDS)) && count(KEYWORDS) === 285);

// ── SQL helpers ─────────────────────────────────────────────────────────────

/**
 * The SQL tokens of a statement: ['w', word] (unquoted), ['q', name] (backtick-quoted), ['s'] (a
 * string literal, content dropped), ['p', char] (punctuation), numbers dropped.
 *
 * @return list<array{0: string, 1?: string}>
 */
function sqlTokens(string $sql): array
{
    preg_match_all("/`((?:[^`]|``)*)`|'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|([A-Za-z_][A-Za-z0-9_\$]*)|([0-9][0-9.]*)|(\\S)/s", $sql, $m, PREG_SET_ORDER);
    $tokens = [];
    foreach ($m as $t) {
        if (($t[1] ?? '') !== '' || str_starts_with($t[0], '`')) { $tokens[] = ['q', $t[1]]; }
        elseif (($t[2] ?? '') !== '')                              { $tokens[] = ['w', $t[2]]; }
        elseif (($t[3] ?? '') !== '')                              { continue; }
        elseif ($t[0][0] === "'" || $t[0][0] === '"')             { $tokens[] = ['s']; }
        else                                                       { $tokens[] = ['p', $t[0]]; }
    }
    return $tokens;
}

/**
 * The unquoted keyword names a statement DEFINES or names in DDL / DML positions, plus every
 * `AS` alias. Statements this parser does not know (SELECT …) still get the alias check.
 *
 * @return list<string> the offending names
 */
function sqlHits(string $sql, callable $isKeyword): array
{
    $t    = sqlTokens($sql);
    $n    = count($t);
    $hits = [];
    $up   = static fn(int $i): string => ($t[$i][0] ?? '') === 'w' ? strtoupper($t[$i][1]) : '';
    $name = static function (int $i) use ($t, $isKeyword, &$hits): void {
        // An identifier position: a keyword there must be quoted. `schema.table` — the part after the dot is free.
        if (($t[$i][0] ?? '') === 'w' && $isKeyword($t[$i][1]) && !(($t[$i - 1][0] ?? '') === 'p' && $t[$i - 1][1] === '.')) {
            $hits[] = $t[$i][1];
        }
    };
    $skip = static function (int $i, array $words) use ($up): int {
        while (in_array($up($i), $words, true)) { $i++; }
        return $i;
    };
    // The names inside the parenthesised list starting at $i (index or column list): first word of each item.
    $list = static function (int $i) use ($t, $n, $name): int {
        if (($t[$i][1] ?? '') !== '(') { return $i; }
        $depth = 0;
        $first = true;
        for (; $i < $n; $i++) {
            $p = $t[$i][0] === 'p' ? $t[$i][1] : '';
            if ($p === '(') { $depth++; if ($depth === 1) { $first = true; continue; } }
            if ($p === ')') { $depth--; if ($depth === 0) { return $i + 1; } }
            if ($depth === 1 && $p === ',') { $first = true; continue; }
            if ($depth === 1 && $first) { $name($i); $first = false; }
        }
        return $i;
    };
    // One item of CREATE TABLE ( … ) or one ALTER TABLE ADD: a column definition or a constraint.
    $item = static function (int $i) use ($t, $up, $name, $list, $skip): void {
        if (!in_array($up($i), CONSTRAINT_HEADS, true)) { $name($i); return; }
        if ($up($i) === 'CONSTRAINT') { $i++; if (!in_array($up($i), CONSTRAINT_HEADS, true)) { $name($i); $i++; } }
        $i = $skip($i, ['UNIQUE', 'PRIMARY', 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'INDEX', 'KEY']);
        if ($up($i) === 'IF') { $i = $skip($i, ['IF', 'NOT', 'EXISTS']); }
        if (($t[$i][1] ?? '(') !== '(') { $name($i); $i++; }
        $i = $list($i);
        if ($up($i) === 'REFERENCES') { $name($i + 1); $list($i + 2); }
    };

    // `AS alias` anywhere — but not a CAST type (`CAST(x AS DECIMAL(15, 2))`, `AS CHAR)`).
    for ($i = 0; $i < $n; $i++) {
        if ($up($i) === 'AS' && ($t[$i + 1][0] ?? '') === 'w' && !in_array($t[$i + 2][1] ?? '', ['(', ')'], true)) { $name($i + 1); }
    }

    $head = $up(0) . ' ' . $up(1);
    if ($head === 'CREATE TABLE' || $head === 'CREATE TEMPORARY') {
        $i = $skip(1, ['TEMPORARY', 'TABLE', 'IF', 'NOT', 'EXISTS']);
        $name($i);
        $i++;
        if (($t[$i][1] ?? '') === '(') {
            $depth = 0;
            for ($start = $i + 1; $i < $n; $i++) {
                $p = $t[$i][0] === 'p' ? $t[$i][1] : '';
                if ($p === '(') { $depth++; continue; }
                if ($p === ')') { $depth--; if ($depth === 0) { $item($start); break; } continue; }
                if ($depth === 1 && $p === ',') { $item($start); $start = $i + 1; }
            }
        }
    } elseif ($head === 'ALTER TABLE') {
        $name(2);
        // The clauses, split at top-level commas.
        $clauses = [[]];
        $depth   = 0;
        for ($i = 3; $i < $n; $i++) {
            $p = $t[$i][0] === 'p' ? $t[$i][1] : '';
            if ($p === '(') { $depth++; }
            if ($p === ')') { $depth--; }
            if ($depth === 0 && $p === ',') { $clauses[] = []; continue; }
            $clauses[count($clauses) - 1][] = $i;
        }
        foreach ($clauses as $c) {
            $i = $c[0] ?? $n;
            switch ($up($i)) {
                case 'ADD':
                    $j = $skip($i + 1, ['COLUMN']);
                    $j = $up($j) === 'IF' ? $skip($j, ['IF', 'NOT', 'EXISTS']) : $j;
                    $item($j);
                    break;
                case 'CHANGE':
                    $j = $skip($i + 1, ['COLUMN']);
                    $j = $up($j) === 'IF' ? $skip($j, ['IF', 'EXISTS']) : $j;
                    $name($j);
                    $name($j + 1);
                    break;
                case 'MODIFY':
                    $j = $skip($i + 1, ['COLUMN']);
                    $name($up($j) === 'IF' ? $skip($j, ['IF', 'EXISTS']) : $j);
                    break;
                case 'RENAME':
                    if (in_array($up($i + 1), ['COLUMN', 'INDEX', 'KEY'], true)) { $name($i + 2); $name($i + 4); }
                    else { $name($up($i + 1) === 'TO' || $up($i + 1) === 'AS' ? $i + 2 : $i + 1); }
                    break;
                case 'DROP':
                    $j = $skip($i + 1, ['COLUMN', 'INDEX', 'KEY', 'FOREIGN', 'CONSTRAINT']);
                    if ($up($i + 1) !== 'PRIMARY') { $name($up($j) === 'IF' ? $skip($j, ['IF', 'EXISTS']) : $j); }
                    break;
            }
        }
    } elseif ($up(0) === 'CREATE' && in_array($up(1), ['INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL'], true)) {
        $i = $skip(1, ['UNIQUE', 'FULLTEXT', 'SPATIAL', 'INDEX']);
        $name($i);
        if ($up($i + 1) === 'ON') { $name($i + 2); $list($i + 3); }
    } elseif ($head === 'DROP TABLE' || $head === 'RENAME TABLE') {
        for ($i = $skip(2, ['IF', 'EXISTS']); $i < $n; $i++) {
            if ($t[$i][0] === 'w' && !in_array($up($i), ['TO', 'CASCADE', 'RESTRICT'], true)) { $name($i); }
        }
    } elseif ($head === 'DROP INDEX') {
        $name(2);
        if ($up(3) === 'ON') { $name(4); }
    } elseif ($head === 'INSERT INTO' || $up(0) === 'REPLACE') {
        $i = $skip(1, ['INTO', 'IGNORE']);
        $name($i);
        $list($i + 1);
    } elseif ($up(0) === 'UPDATE') {
        $name($up(1) === 'IGNORE' ? 2 : 1);
        // `SET a = …, b = …` — the target of each assignment, at top level.
        for ($i = 0, $depth = 0, $inSet = false, $expect = false; $i < $n; $i++) {
            $p = $t[$i][0] === 'p' ? $t[$i][1] : '';
            if ($p === '(') { $depth++; }
            if ($p === ')') { $depth--; }
            if ($depth !== 0) { continue; }
            if ($up($i) === 'SET') { $inSet = true; $expect = true; continue; }
            if ($up($i) === 'WHERE' || $up($i) === 'ORDER' || $up($i) === 'LIMIT') { $inSet = false; }
            if ($inSet && $p === ',') { $expect = true; continue; }
            if ($inSet && $expect && $t[$i][0] !== 'p') {
                // `alias.column` — the column after the dot is free, the alias before it is checked.
                $name($i);
                $expect = false;
            }
        }
    }
    // FROM / JOIN <table> in any statement (a subquery's parenthesis is skipped).
    for ($i = 0; $i < $n; $i++) {
        if (in_array($up($i), ['FROM', 'JOIN'], true) && ($t[$i + 1][0] ?? '') === 'w' && $up($i + 1) !== 'DUAL') { $name($i + 1); }
    }

    return array_values(array_unique($hits));
}

/**
 * The SQL strings of a PHP file: the first argument of every call to one of $methods, string
 * pieces concatenated, a non-literal piece (variable, constant, call) replaced by `?`.
 *
 * @return list<array{line: int, sql: string}>
 */
function sqlArguments(string $file, array $methods): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $out    = [];
    $count  = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || !in_array($tokens[$i][1], $methods, true)) { continue; }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (($tokens[$j] ?? '') !== '(') { continue; }
        $sql   = '';
        $depth = 0;
        $line  = $tokens[$i][2];
        for ($j++; $j < $count; $j++) {
            $tok = $tokens[$j];
            if ($tok === '(' || $tok === '[') { $depth++; continue; }
            if ($tok === ')' || $tok === ']') { if ($depth === 0) { break; } $depth--; continue; }
            if ($tok === ',' && $depth === 0) { break; }
            if ($depth > 0) { continue; }
            if (is_array($tok)) {
                if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $sql .= $tok[1][0] === "'" ? stripcslashes(str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tok[1], 1, -1))) : stripcslashes(substr($tok[1], 1, -1));
                } elseif ($tok[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $sql .= $tok[1];
                } elseif (in_array($tok[0], [T_VARIABLE, T_STRING, T_DOUBLE_COLON, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $sql .= '?';
                }
            }
        }
        $out[] = ['line' => $line, 'sql' => $sql];
    }
    return $out;
}

// ── O. the ORM mapping ──────────────────────────────────────────────────────

echo "O. ORM mapping — tables, columns, join columns, indexes\n";

$entityClasses = [];
$walk =static function (string $dir) use (&$walk): array {
    $files = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $path = $dir . '/' . $f;
        if (is_dir($path)) { $files = [...$files, ...$walk($path)]; }
        elseif (str_ends_with($f, '.php')) { $files[] = $path; }
    }
    return $files;
};
foreach (glob($root . '/packages/*/src', GLOB_ONLYDIR) ?: [] as $src) {
    foreach ($walk($src) as $file) {
        $code = (string) file_get_contents($file);
        if (!preg_match('/^\s*#\[ORM\\\\Entity\b/m', $code) || !preg_match('/^namespace\s+([^;]+);/m', $code, $ns) || !preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $code, $cls)) { continue; }
        $entityClasses[] = $ns[1] . '\\' . $cls[1];
    }
}
sort($entityClasses);

if (!Type::hasType(MoneyType::NAME)) {
    Type::addType(MoneyType::NAME, MoneyType::class);
}
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver(new ClassNames($entityClasses)));
$config->enableNativeLazyObjects(true);
// Never connects: the platform comes from serverVersion, metadata needs no database.
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '10.6.0-MariaDB'], $config), $config);

$ormNames  = [];   // "table.column" => true — what the mapping defines
$ormHits   = [];
$loadError = '';
try {
    $metas = $em->getMetadataFactory()->getAllMetadata();
} catch (\Throwable $e) {
    $metas     = [];
    $loadError = get_class($e) . ': ' . $e->getMessage();
}
$flag = static function (string $where, string $name, bool $quoted) use ($isKeyword, &$ormHits): void {
    if (!$quoted && $isKeyword($name)) { $ormHits[] = $where . ' `' . $name . '`'; }
};
foreach ($metas as $meta) {
    /** @var ClassMetadata $meta */
    $table = $meta->getTableName();
    $flag($meta->getName() . ' table', $table, !empty($meta->table['quoted']));
    foreach ($meta->fieldMappings as $field => $mapping) {
        $flag($meta->getName() . '::$' . $field, $mapping->columnName, !empty($mapping->quoted));
        $ormNames[$table . '.' . $mapping->columnName] = true;
    }
    foreach ($meta->associationMappings as $field => $assoc) {
        foreach (($assoc->joinColumns ?? []) as $jc) {
            $flag($meta->getName() . '::$' . $field . ' join column', $jc->name, !empty($jc->quoted));
        }
        if (isset($assoc->joinTable)) {
            $flag($meta->getName() . '::$' . $field . ' join table', $assoc->joinTable->name, !empty($assoc->joinTable->quoted));
        }
    }
    foreach (['indexes', 'uniqueConstraints'] as $kind) {
        foreach (($meta->table[$kind] ?? []) as $indexName => $index) {
            $flag($meta->getName() . ' ' . $kind, (string) $indexName, false);
        }
    }
    // Embedded columns are in fieldMappings already (prefixed) — nothing extra to read.
}
$loaded = array_map(static fn(ClassMetadata $m): string => $m->getName(), $metas);
check('O1 the mapping of every entity in packages/*/src loads (' . count($entityClasses) . ' entities, ' . count($ormNames) . ' columns)',
    $loadError === '' && array_diff($entityClasses, $loaded) === [] && count($entityClasses) >= 50, $loadError . implode(', ', array_diff($entityClasses, $loaded)) . ' / extra: ' . implode(', ', array_diff($loaded, $entityClasses)));
check('O2 no table, column, join column, join table or index of the mapping is a reserved word / keyword of MySQL 8.x or MariaDB ≤ 13 — unless the mapping quotes it', $ormHits === [], implode(', ', $ormHits));
check('O3 bank_statement maps period_from / period_to — `to_date` (a keyword since MariaDB 12.3) is gone from the mapping',
    isset($ormNames['bank_statement.period_from'], $ormNames['bank_statement.period_to']) && !isset($ormNames['bank_statement.to_date']) && !isset($ormNames['bank_statement.from_date']));

// ── M. the migrations ───────────────────────────────────────────────────────

echo "M. Migrations — every addSql() and connection call\n";

$sqlMethods = ['addSql', 'executeStatement', 'executeQuery', 'fetchOne', 'fetchAssociative', 'fetchAllAssociative', 'fetchFirstColumn', 'fetchAllKeyValue', 'fetchNumeric', 'fetchAllNumeric', 'iterateAssociative'];
$migrationFiles = glob($root . '/packages/*/res/migrations/Version*.php') ?: [];
$statements     = 0;
$migHits        = [];
foreach ($migrationFiles as $file) {
    foreach (sqlArguments($file, $sqlMethods) as $s) {
        $statements++;
        foreach (sqlHits($s['sql'], $isKeyword) as $hit) {
            $migHits[] = substr($file, strlen($root) + 1) . ':' . $s['line'] . ' `' . $hit . '`';
        }
    }
}
check('M1 every migration is read (' . count($migrationFiles) . ' files, ' . $statements . ' SQL statements)', count($migrationFiles) >= 60 && $statements >= 300);
check('M2 no migration names a reserved word / keyword unquoted (a fresh install on MariaDB 12.3 / 13 or MySQL 8 runs every one)', $migHits === [], implode(', ', $migHits));

// The parser itself: it must see what broke the install, and must not cry wolf.
$selfTest = [
    'CREATE TABLE bank_statement (id INT NOT NULL, from_date DATE NOT NULL, to_date DATE NOT NULL, PRIMARY KEY (id))'           => ['to_date'],
    'CREATE TABLE t (id INT NOT NULL, `to_date` DATE NOT NULL, rank INT NOT NULL, INDEX idx_x (rank), PRIMARY KEY (id))'       => ['rank'],
    'ALTER TABLE t ADD row INT NOT NULL, CHANGE `to_date` period_to DATE NOT NULL, RENAME COLUMN a TO offset'                 => ['row', 'offset'],
    'ALTER TABLE t ADD CONSTRAINT FK_1 FOREIGN KEY (account_id) REFERENCES account (id), ADD UNIQUE INDEX uniq_x (code, vector)' => ['vector'],
    "UPDATE bank_account a SET a.mixed_use = 1, returning = 'x' WHERE EXISTS (SELECT 1 FROM bank_pattern p WHERE p.kind IN ('to_date'))" => ['returning'],
    'SELECT CAST(amount AS DECIMAL(15, 2)) AS total, MAX(x) AS rank FROM journal_line'                                          => ['rank'],
    'INSERT INTO t (id, qualify) SELECT id, name FROM u'                                                                       => ['qualify'],
    'CREATE TABLE ok (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, text VARCHAR(10) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`' => [],
];
$selfBad = [];
foreach ($selfTest as $sql => $expected) {
    $got = sqlHits($sql, $isKeyword);
    if ($got !== $expected) { $selfBad[] = substr($sql, 0, 50) . '… → [' . implode(', ', $got) . ']'; }
}
check('M3 the SQL check finds an unquoted keyword as a column, an index column, an ADD / CHANGE / RENAME target, a SET target, an INSERT column and an alias — and passes a quoted one, a CAST type, a qualified name, a string literal and plain names', $selfBad === [], implode(' | ', $selfBad));

// ── R. raw SQL ──────────────────────────────────────────────────────────────

echo "R. Raw SQL — aliases in packages/*/src and tests/\n";

$rawFiles = [...glob($root . '/tests/*.php') ?: []];
foreach (glob($root . '/packages/*/src', GLOB_ONLYDIR) ?: [] as $src) {
    $rawFiles = [...$rawFiles, ...$walk($src)];
}
$rawHits = [];
$rawSql  = 0;
foreach ($rawFiles as $file) {
    if (str_ends_with($file, '/tests/schema-reserved-words.php')) { continue; }   // its own fixtures above
    foreach (token_get_all((string) file_get_contents($file)) as $tok) {
        if (!is_array($tok) || !in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { continue; }
        // SQL in this code base is written with upper-case keywords; an English sentence «… as to …» is not SQL.
        if (!preg_match('/\b(SELECT|UPDATE|INSERT|DELETE)\b/', $tok[1]) && !preg_match('/\bAS\s/', $tok[1])) { continue; }
        $rawSql++;
        preg_match_all('/\bAS\s+([A-Za-z_][A-Za-z0-9_]*)/', $tok[1], $m);
        foreach ($m[1] as $alias) {
            // CAST(x AS DECIMAL(15, 2)) / AS CHAR) — a type, not an alias.
            if ($isKeyword($alias) && !in_array(strtoupper($alias), ['CHAR', 'DECIMAL', 'SIGNED', 'UNSIGNED', 'DATE', 'BINARY', 'INTEGER', 'DOUBLE', 'FLOAT'], true)) {
                $rawHits[] = substr($file, strlen($root) + 1) . ':' . $tok[2] . ' AS ' . $alias;
            }
        }
    }
}
check('R1 no raw SQL alias (`AS name`) in packages/*/src or tests/ is a reserved word / keyword (' . $rawSql . ' SQL strings read)', $rawSql > 100 && $rawHits === [], implode(', ', $rawHits));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
