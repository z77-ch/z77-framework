<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, eighth migration — bank-driven bookkeeping, stage C2 (ADR-061 decisions 4–8,
 * 14–21, owner 2026-11-17): the state of a bank movement and the direct posting.
 *
 *   - `bank_pattern` — what the office decided once for a counterparty / merchant / text of a bank
 *     account and direction (`match_key`): `kind` private · mixed («gemischt») · business, the
 *     optional narrowing (approximate amount, day of month) and the booking proposal (contra
 *     account, tax code). One per bank account, direction and key;
 *   - `bank_movement_mark` — the office's decision BY HAND for one movement (private · review ·
 *     exception), at most one per movement;
 *   - `bank_posting` — «Geschäftlich buchen …»: the owner of the movement's link and of the
 *     generated journal entry (year + number), the contra account, the tax code, the archived
 *     voucher (path, SHA-256, name), and — after «Buchung zurücknehmen …» — the reversing entry;
 *   - `bank_movement.pattern_key` — the key a pattern is matched by, derived from the movement's
 *     own data, with an index per bank account. NOT a state: the state is derived
 *     (`BankMovementRepository::STATE`).
 *
 * Expand only (ADR-039 decision 14): three new tables and one new column with a default; no
 * existing value is changed. {@see postUp()} fills `pattern_key` for the movements already
 * stored — by the rule as it stands TODAY, frozen in {@see keyOf()} (a copy of
 * `BankPattern::keyOf()` on purpose: a migration must not change when the entity does).
 */
final class Version20261117170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: bank_pattern, bank_movement_mark, bank_posting, bank_movement.pattern_key — states, learned patterns, «Geschäftlich buchen …» (ADR-061 stage C2, 2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_movement_mark (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(10) NOT NULL, marked_by VARCHAR(80) NOT NULL, marked_at DATETIME NOT NULL, movement_id INT NOT NULL, UNIQUE INDEX uniq_bank_movement_mark_movement (movement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE bank_pattern (id INT AUTO_INCREMENT NOT NULL, direction VARCHAR(6) NOT NULL, match_key VARCHAR(80) NOT NULL, label VARCHAR(140) NOT NULL, kind VARCHAR(10) NOT NULL, amount_min NUMERIC(15, 2) DEFAULT NULL, amount_max NUMERIC(15, 2) DEFAULT NULL, day_of_month SMALLINT DEFAULT NULL, contra_account VARCHAR(10) DEFAULT NULL, tax_code VARCHAR(8) DEFAULT NULL, confirmed_by VARCHAR(80) NOT NULL, confirmed_at DATETIME NOT NULL, bank_account_id INT NOT NULL, UNIQUE INDEX uniq_bank_pattern_key (bank_account_id, direction, match_key), INDEX IDX_8D28942012CB990C (bank_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE bank_posting (id INT AUTO_INCREMENT NOT NULL, contra_account VARCHAR(10) NOT NULL, tax_code VARCHAR(8) DEFAULT NULL, entry_year VARCHAR(16) DEFAULT NULL, entry_number INT DEFAULT NULL, voucher_path VARCHAR(255) DEFAULT NULL, voucher_sha256 CHAR(64) DEFAULT NULL, voucher_name VARCHAR(255) DEFAULT NULL, posted_by VARCHAR(80) NOT NULL, posted_at DATETIME NOT NULL, reversal_year VARCHAR(16) DEFAULT NULL, reversal_number INT DEFAULT NULL, taken_back_by VARCHAR(80) DEFAULT NULL, taken_back_at DATETIME DEFAULT NULL, movement_id INT NOT NULL, INDEX idx_bank_posting_movement (movement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE bank_movement_mark ADD CONSTRAINT FK_34FFCC60229E70A7 FOREIGN KEY (movement_id) REFERENCES bank_movement (id)');
        $this->addSql('ALTER TABLE bank_pattern ADD CONSTRAINT FK_8D28942012CB990C FOREIGN KEY (bank_account_id) REFERENCES bank_account (id)');
        $this->addSql('ALTER TABLE bank_posting ADD CONSTRAINT FK_93B335DD229E70A7 FOREIGN KEY (movement_id) REFERENCES bank_movement (id)');
        $this->addSql('ALTER TABLE bank_movement ADD pattern_key VARCHAR(80) DEFAULT \'\' NOT NULL');
        $this->addSql('CREATE INDEX idx_bank_movement_pattern ON bank_movement (bank_account_id, pattern_key)');
    }

    /** The movements stored before this stage get their key — in id order, a batch at a time. */
    public function postUp(Schema $schema): void
    {
        // A dry run (`migrate --dry-run`) does not execute up()'s statements but still calls this
        // hook, which writes through the connection directly: without the column there is nothing
        // to fill, and the UPDATE would fail (fixed 2026-11-17; a real run always has the column).
        $hasColumn = $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_movement' AND COLUMN_NAME = 'pattern_key'"
        );
        if ($hasColumn === false) {
            return;
        }
        $last = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, counterparty_iban, counterparty_name, text FROM bank_movement WHERE id > ? ORDER BY id LIMIT 500',
                [$last]
            );
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                $key  = self::keyOf((string) $row['counterparty_iban'], (string) $row['counterparty_name'], (string) $row['text']);
                if ($key !== '') {
                    $this->connection->executeStatement('UPDATE bank_movement SET pattern_key = ? WHERE id = ?', [$key, $last]);
                }
            }
        } while ($rows !== []);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_movement_mark DROP FOREIGN KEY FK_34FFCC60229E70A7');
        $this->addSql('ALTER TABLE bank_pattern DROP FOREIGN KEY FK_8D28942012CB990C');
        $this->addSql('ALTER TABLE bank_posting DROP FOREIGN KEY FK_93B335DD229E70A7');
        $this->addSql('DROP TABLE bank_movement_mark');
        $this->addSql('DROP TABLE bank_pattern');
        $this->addSql('DROP TABLE bank_posting');
        $this->addSql('DROP INDEX idx_bank_movement_pattern ON bank_movement');
        $this->addSql('ALTER TABLE bank_movement DROP pattern_key');
    }

    /** The key rule of 2026-11-17, frozen: the IBAN, else the merchant after «Bezugsort:», else the name, else the text without its numbers. */
    private static function keyOf(string $iban, string $name, string $text): string
    {
        $norm = static fn(string $value): string => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
        $cut  = static fn(string $prefix, string $value): string => $prefix . mb_substr($value, 0, 80 - strlen($prefix));
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if ($iban !== '') {
            return $cut('iban:', $iban);
        }
        if (preg_match('/Bezugsort:\s*([^,;\r\n]+)/iu', $text, $m) === 1 && trim($m[1]) !== '') {
            return $cut('merchant:', $norm($m[1]));
        }
        if ($norm($name) !== '') {
            return $cut('name:', $norm($name));
        }
        $plain = $norm((string) preg_replace('/[\d.,:;\/\-]+/u', ' ', $text));

        return $plain === '' ? '' : $cut('text:', $plain);
    }
}
