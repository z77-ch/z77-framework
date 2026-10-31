<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;

/**
 * The word the member area uses for what a member works FOR — «Verwaltung» in AXO3,
 * «Projekt» in rudaz (memberConfig `tenantNoun`, 2026-10-31). The module knows no
 * tenants; it only has to NAME them in its pages and mails, with the right German
 * article.
 *
 * Config: `'tenantNoun' => ['singular' => 'Projekt', 'plural' => 'Projekte', 'gender' => 'n']`
 * (gender `f` / `m` / `n`). Missing or invalid parts fall back to the default
 * «Verwaltung / Verwaltungen / f», so a project without the key reads as before.
 *
 * Cases: nominative, accusative and dative only. The genitive is left out on
 * purpose — the noun itself inflects there (des Projekts), which a config entry
 * cannot know; texts are phrased without it.
 *
 *   $n->with('der', 'nom')              → die Verwaltung / das Projekt
 *   $n->with('dies', 'acc')             → diese Verwaltung / dieses Projekt
 *   $n->with('ein', 'dat', 'bestehend') → einer bestehenden Verwaltung / einem bestehenden Projekt
 *   $n->zu()                            → zur Verwaltung / zum Projekt
 *   $n->pronoun('acc')                  → sie / es
 */
final class TenantNoun
{
    private const GENDERS = ['f', 'm', 'n'];
    private const CASES   = ['nom', 'acc', 'dat'];

    /** Definite article = also the relative pronoun in these three cases. */
    private const DEFINITE = [
        'nom' => ['m' => 'der', 'f' => 'die', 'n' => 'das'],
        'acc' => ['m' => 'den', 'f' => 'die', 'n' => 'das'],
        'dat' => ['m' => 'dem', 'f' => 'der', 'n' => 'dem'],
    ];

    /** Endings of der-words (dies-, welch-, jen-). */
    private const DER_WORD = [
        'nom' => ['m' => 'er', 'f' => 'e', 'n' => 'es'],
        'acc' => ['m' => 'en', 'f' => 'e', 'n' => 'es'],
        'dat' => ['m' => 'em', 'f' => 'er', 'n' => 'em'],
    ];

    /** Endings of ein-words (ein, kein, Ihr, unser). */
    private const EIN_WORD = [
        'nom' => ['m' => '', 'f' => 'e', 'n' => ''],
        'acc' => ['m' => 'en', 'f' => 'e', 'n' => ''],
        'dat' => ['m' => 'em', 'f' => 'er', 'n' => 'em'],
    ];

    /** Adjective endings after a der-word (weak) and after an ein-word (mixed). */
    private const ADJ_WEAK = [
        'nom' => ['m' => 'e', 'f' => 'e', 'n' => 'e'],
        'acc' => ['m' => 'en', 'f' => 'e', 'n' => 'e'],
        'dat' => ['m' => 'en', 'f' => 'en', 'n' => 'en'],
    ];
    private const ADJ_MIXED = [
        'nom' => ['m' => 'er', 'f' => 'e', 'n' => 'es'],
        'acc' => ['m' => 'en', 'f' => 'e', 'n' => 'es'],
        'dat' => ['m' => 'en', 'f' => 'en', 'n' => 'en'],
    ];

    private const PRONOUN = [
        'nom' => ['m' => 'er', 'f' => 'sie', 'n' => 'es'],
        'acc' => ['m' => 'ihn', 'f' => 'sie', 'n' => 'es'],
        'dat' => ['m' => 'ihm', 'f' => 'ihr', 'n' => 'ihm'],
    ];

    private const EIN_WORDS = ['ein', 'kein', 'Ihr', 'ihr', 'unser', 'mein', 'sein'];

    private readonly string $singular;
    private readonly string $plural;
    private readonly string $gender;

    public function __construct(string $singular = 'Verwaltung', string $plural = 'Verwaltungen', string $gender = 'f')
    {
        $singular = trim($singular);
        $valid    = $singular !== '' && in_array($gender, self::GENDERS, true);
        $this->singular = $valid ? $singular : 'Verwaltung';
        $this->gender   = $valid ? $gender : 'f';
        $this->plural   = $valid ? (trim($plural) !== '' ? trim($plural) : $singular) : 'Verwaltungen';
    }

    /** @param array<string, mixed> $raw the memberConfig `tenantNoun` value */
    public static function fromArray(array $raw): self
    {
        return new self(
            is_string($raw['singular'] ?? null) ? $raw['singular'] : '',
            is_string($raw['plural'] ?? null) ? $raw['plural'] : '',
            is_string($raw['gender'] ?? null) ? strtolower($raw['gender']) : '',
        );
    }

    /** The configured noun; the default when the key is missing or no config is reachable (offline harness). */
    public static function fromConfig(): self
    {
        try {
            $raw = DI::getConfigManager()
                ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
                ->get('tenantNoun', []);
        } catch (\Throwable) {
            return new self(); // wording only — never a reason to break a page or a mail
        }

        return is_array($raw) && $raw !== [] ? self::fromArray($raw) : new self();
    }

    public function singular(): string
    {
        return $this->singular;
    }

    public function plural(): string
    {
        return $this->plural;
    }

    /**
     * The determiner in the noun's gender: 'der' (definite), a der-word stem ('dies',
     * 'welch', 'jen') or an ein-word ('ein', 'kein', 'Ihr', …).
     */
    public function article(string $determiner, string $case = 'nom'): string
    {
        $case = self::checkCase($case);
        if ($determiner === 'der') {
            return self::DEFINITE[$case][$this->gender];
        }
        $table = in_array($determiner, self::EIN_WORDS, true) ? self::EIN_WORD : self::DER_WORD;

        return $determiner . $table[$case][$this->gender];
    }

    /** Determiner, optional adjective (stem, e.g. 'bestehend') and the noun. */
    public function with(string $determiner, string $case = 'nom', string $adjective = ''): string
    {
        $case = self::checkCase($case);
        $adj  = '';
        if ($adjective !== '') {
            $table = in_array($determiner, self::EIN_WORDS, true) ? self::ADJ_MIXED : self::ADJ_WEAK;
            $adj   = $adjective . $table[$case][$this->gender] . ' ';
        }

        return $this->article($determiner, $case) . ' ' . $adj . $this->singular;
    }

    /** «zu» + dative: zur Verwaltung / zum Projekt. */
    public function zu(): string
    {
        return ($this->gender === 'f' ? 'zur ' : 'zum ') . $this->singular;
    }

    /** The personal pronoun that refers back to the noun (sie / es / er …). */
    public function pronoun(string $case = 'nom'): string
    {
        return self::PRONOUN[self::checkCase($case)][$this->gender];
    }

    private static function checkCase(string $case): string
    {
        if (!in_array($case, self::CASES, true)) {
            throw new \InvalidArgumentException("TenantNoun: case '{$case}' is not supported (nom, acc, dat).");
        }

        return $case;
    }
}
