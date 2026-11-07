<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;

/**
 * The member's language, in the four places the module needs it (ADR-056, z77-rudaz.ch
 * 2026-11-07):
 *
 *   of()            the language a mail to this account goes out in — its own, else the default
 *   forNewAccount() what a self-registration records — the page's language, where the
 *                   module renders accounts in their own language at all
 *   offered()       the languages the profile offers — the module's `languages` (memberConfig)
 *   url()           a mail link in the recipient's language — the guest who clicks it lands
 *                   on a page in that language, before any session says so
 *
 * Every answer is a configured language ({@see \Z77\Core\Services\I18n::resolve()}): an
 * account still carrying a language the installation dropped since gets the default, never
 * a broken mail.
 */
final class MemberLanguage
{
    /** memberConfig key: render a signed-in member in her account's language (ADR-056). */
    public const CONFIG_KEY = 'languageFromAccount';

    /** The language mails to $account go out in. */
    public static function of(MemberAccount $account): string
    {
        return self::resolve($account->getLanguage());
    }

    /** A code made safe: a configured language, or the default. */
    public static function resolve(?string $language): string
    {
        return DI::getI18n()->resolve($language);
    }

    /**
     * A code to STORE (token, account): null for «default» — so a row only carries a language
     * someone chose — and null for a code the installation does not offer.
     */
    public static function storable(?string $language): ?string
    {
        $language = MemberAccount::normalizeLanguage($language);
        if ($language === null) {
            return null;
        }
        $i18n = DI::getI18n();

        return $i18n->isValidLanguage($language) && $language !== $i18n->getDefaultLanguage() ? $language : null;
    }

    /** Does this installation render signed-in members in their own language? */
    public static function fromAccount(): bool
    {
        try {
            return DI::getConfigManager()
                ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
                ->get(self::CONFIG_KEY, false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The language a self-registration records: the page's, when the module renders accounts
     * in their own language — whoever registered on `/fr/…` reads French afterwards. Without
     * the setting nothing is recorded, and nothing changes for such an installation.
     */
    public static function forNewAccount(): ?string
    {
        if (!self::fromAccount()) {
            return null;
        }
        try {
            return self::storable(DI::getRequest()->getLanguage());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The languages a member may choose on the profile: the module's `languages` (the same
     * key that offers the guest a switch), only where the module renders accounts in their
     * own language. Fewer than two = no choice to offer.
     *
     * @return list<string>
     */
    public static function offered(): array
    {
        if (!self::fromAccount()) {
            return [];
        }
        try {
            $languages = DI::getModuleManager()->getModuleLanguages('member');
        } catch (\Throwable) {
            return [];
        }

        return count($languages) > 1 ? $languages : [];
    }

    /**
     * An absolute member URL in $language: the prefix after the origin (`https://x.ch/fr/member/…`),
     * none for the default language. Member paths are technical — no alias, nothing to
     * translate, only the prefix ({@see localizedUrl()} would do the same for a path).
     */
    public static function url(string $absoluteUrl, string $language): string
    {
        if ($language === DI::getI18n()->getDefaultLanguage()
            || preg_match('#^(https?://[^/?\#]+)(/[^?\#]*)?(.*)$#i', $absoluteUrl, $m) !== 1) {
            return $absoluteUrl;
        }
        $path = $m[2] !== '' ? $m[2] : '/';

        return $m[1] . '/' . $language . ($path === '/' ? '' : $path) . $m[3];
    }
}
