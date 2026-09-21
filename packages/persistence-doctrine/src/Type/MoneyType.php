<?php

namespace Z77\Persistence\Doctrine\Type;

use Doctrine\DBAL\ParameterType,
    Doctrine\DBAL\Platforms\AbstractPlatform,
    Doctrine\DBAL\Types\ConversionException,
    Doctrine\DBAL\Types\Exception\InvalidType,
    Doctrine\DBAL\Types\Exception\ValueNotConvertible,
    Doctrine\DBAL\Types\Type,
    Z77\Shared\Money\Money
;

/**
 * `Money` ↔ `DECIMAL(15,2)` (ADR-042 decision 3): the column is readable and
 * `SUM()` in SQL is exact; PHP sees integer minor units. The value travels as
 * a decimal STRING in both directions — `Money::toDecimal()` out,
 * `Money::fromDecimal()` in — and never through a float: a float on either
 * side is refused, not converted.
 *
 * The column carries no currency (decision 4: the database holds the base
 * currency only), so every amount is read in the ONE currency the driver
 * configures at boot — and an amount in any other currency is refused on the
 * way in, because it would come back as the base currency. Precision and
 * scale are fixed here, not per column — a `Money` column is DECIMAL(15,2)
 * wherever it appears — and an amount the column cannot hold is refused
 * here, before SQL: a lenient sql_mode would clamp it to the maximum without
 * a word (the connection pins a strict mode as well; this check is the one
 * that does not depend on the server).
 *
 * Mapping: `#[ORM\Column(type: MoneyType::NAME)]` on a `Money` property.
 */
final class MoneyType extends Type
{
    public const NAME      = 'money';
    public const PRECISION = 15;
    public const SCALE     = 2;

    /** Largest |minor| a DECIMAL(15,2) holds: 13 integer digits + 2 decimals. */
    private const MAX_MINOR = 999_999_999_999_999;

    private static string $currency = '';

    /** Set once at boot from the installation's base currency. */
    public static function useCurrency(string $currency): void
    {
        self::$currency = $currency;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDecimalTypeDeclarationSQL(
            ['precision' => self::PRECISION, 'scale' => self::SCALE] + $column
        );
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Money
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            // The driver would already have lost the exact amount here (DECIMAL
            // read as float) — the one path this type exists to close.
            throw ValueNotConvertible::new($value, self::NAME, 'DECIMAL came back as float, never through float');
        }
        if (self::$currency === '') {
            throw new \LogicException('MoneyType has no currency — the driver boot sets it (Bootstrap).');
        }

        return Money::fromDecimal((string)$value, self::$currency);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof Money) {
            throw InvalidType::new($value, self::NAME, [Money::class, 'null']);
        }
        if ($value->currency !== self::$currency) {
            throw new ConversionException(
                "Cannot store {$value} in a money column: the database holds the base currency "
                . self::$currency . " only (ADR-042 decision 4) — convert the amount first."
            );
        }
        if (abs($value->minor) > self::MAX_MINOR) {
            throw new ConversionException(
                "Cannot store {$value} in a money column: outside DECIMAL(" . self::PRECISION . ',' . self::SCALE . ')'
            );
        }

        return $value->toDecimal();
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::STRING;
    }
}
