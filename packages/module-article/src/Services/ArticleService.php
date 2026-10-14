<?php

namespace Z77\Module\Article\Services;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Z77\Core\DI;
use Z77\Module\Article\Entities\AbstractProduct;
use Z77\Module\Article\Entities\Price;
use Z77\Module\Article\Entities\Product;
use Z77\Module\Article\Entities\ProductGroup;
use Z77\Module\Article\Entities\Variant;
use Z77\Module\Article\Repositories\PriceRepository;
use Z77\Module\Article\Repositories\ProductGroupRepository;
use Z77\Module\Article\Repositories\ProductRepository;
use Z77\Module\Article\Repositories\VariantRepository;
use Z77\Module\Article\Validators\ArticleValidator;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The ONE write path of the article master (plan §4.1, P6 slim): {@see create()}, {@see update()},
 * {@see setActive()}, {@see delete()}. A {@see ArticleDraft} in — no request —, so the backend
 * dialog, a later import or an API call write the same way.
 *
 * What one save does — product, default variant and price together, in ONE unit of work:
 *
 *   - validates the draft BEFORE anything managed is touched (ADR-039 decision 9): number (free,
 *     unique — A2), name, unit, price (not negative), net / gross, «gültig ab», the tax code
 *     (module-vat: an ACTIVE code, or the one the article keeps — ADR-043 decision 19) and the
 *     revenue account (module-mandator's `LedgerAccountCheck`: postable, type «Ertrag» AND «auf
 *     Belegen wählbar» — SOFT: without module-financial the number is stored unverified);
 *   - create: the product in its ONE product group (A1 — mandatory, any node), its DEFAULT
 *     variant (empty label — A3 in the schema, hidden in the UI) and the first price row. A tax
 *     code or revenue account left EMPTY is taken from the group's defaults (inherited from the
 *     nearest group above that sets one, {@see ProductGroupTree::defaultsFor()}) BEFORE the draft
 *     is validated — a NEW article only; an update never reads a default (owner 2026-10-14:
 *     existing articles never change when a group default changes);
 *   - update: only with the VERSION the form was rendered from (a stale one is
 *     {@see ArticleConflictException}); the price follows the A4 rule — a row starting that day is
 *     REPLACED, otherwise a NEW row is written when the price valid that day says something else,
 *     and nothing when it already says the same (owner Q3, 2026-10-14);
 *   - setActive: product and its variants together (a referenced article is deactivated, never
 *     deleted — the reference rule applied to rows);
 *   - delete: only while nothing references a variant. Article does not know who references it
 *     (module-order's line has a foreign key on `article_variant`); the database refuses the delete
 *     and the refusal becomes {@see ArticleInUseException} — answer: deactivate.
 *
 * The actor is named by the caller (the screen: the logged-in user). Stock is NOT written here:
 * «führt Bestand» is a flag the stock service reads per variant (plan §8.1); the balance has its
 * own write path (`Stock\StockLedger`, ADR-040 decision 8).
 */
final class ArticleService
{
    private readonly string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, string $actor)
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new \LogicException('An article is written by a named actor — pass the user name');
        }
        $this->actor = mb_substr($actor, 0, AbstractProduct::ACTOR_LENGTH);
    }

    /** The installation's base currency — `systemConfig → baseCurrency`, held once (Rule 2, ADR-042 decision 4). */
    public static function currency(): string
    {
        return (string) DI::getConfigManager()
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false)
            ->get('baseCurrency', 'CHF');
    }

    // ── writes ───────────────────────────────────────────────────────────

    /** @throws InvalidArticleException */
    public function create(ArticleDraft $draft): Product
    {
        $draft = $this->withGroupDefaults($draft);
        $this->assertValid($draft, null);
        [$amount, $mode, $day] = $this->priceOf($draft);

        $product = null;
        $this->refuseNumberRace($draft, function () use ($draft, $amount, $mode, $day, &$product): void {
            $this->em->getTransaction(Product::class)->run(function () use ($draft, $amount, $mode, $day, &$product): void {
                $product = new Product($this->actor, new \DateTimeImmutable());
                $product->describe($draft->name, $draft->taxCode, $draft->revenueAccount, $draft->stockRelevant);
                $product->placeIn($this->group($draft->productGroup));
                $this->em->persist($product);

                $variant = new Variant($product);
                $variant->identify($draft->number, $draft->unit);
                $this->em->persist($variant);

                $this->em->persist(new Price($variant, $day, $amount, $mode));
            });
        });

        return $product;
    }

    /**
     * @throws ArticleConflictException the article is gone or its version moved on
     * @throws InvalidArticleException
     */
    public function update(int $productId, int $version, ArticleDraft $draft): Product
    {
        $product = $this->products()->find($productId);
        if ($product === null || $product->getVersion() !== $version) {
            throw new ArticleConflictException($productId, $version);
        }
        $variant = $this->variants()->defaultOf($product) ?? throw new \LogicException("Article {$productId} has no default variant");

        $this->assertValid($draft, $product);
        [$amount, $mode, $day] = $this->priceOf($draft);

        try {
            $this->refuseNumberRace($draft, function () use ($product, $variant, $draft, $amount, $mode, $day): void {
                $this->em->getTransaction(Product::class)->run(function () use ($product, $variant, $draft, $amount, $mode, $day): void {
                    $product->describe($draft->name, $draft->taxCode, $draft->revenueAccount, $draft->stockRelevant);
                    $product->placeIn($this->group($draft->productGroup));
                    // Always stamped: the product row is written on every save, so its version moves
                    // even when only the price changed — a form rendered before is then stale.
                    $product->touch($this->actor, new \DateTimeImmutable());
                    $variant->identify($draft->number, $draft->unit);
                    $this->writePrice($variant, $day, $amount, $mode);
                });
            });
        } catch (OptimisticLockException) {
            throw new ArticleConflictException($productId, $version);
        }

        return $product;
    }

    /** Active / inactive — the product and every variant together. @throws ArticleConflictException the article is gone */
    public function setActive(int $productId, bool $active): Product
    {
        $product = $this->products()->find($productId) ?? throw new ArticleConflictException($productId, 0);

        $this->em->getTransaction(Product::class)->run(function () use ($product, $active): void {
            $product->setActive($active);
            $product->touch($this->actor, new \DateTimeImmutable());
            foreach ($this->variants()->allOf($product) as $variant) {
                $variant->setActive($active);
            }
        });

        return $product;
    }

    /**
     * Deletes an article nothing references: its prices, its variants, the product.
     *
     * @throws ArticleConflictException the article is gone or its version moved on
     * @throws ArticleInUseException    a row elsewhere references a variant — deactivate instead
     */
    public function delete(int $productId, int $version): void
    {
        $product = $this->products()->find($productId);
        if ($product === null || $product->getVersion() !== $version) {
            throw new ArticleConflictException($productId, $version);
        }

        try {
            $this->em->getTransaction(Product::class)->run(function () use ($product): void {
                foreach ($this->variants()->allOf($product) as $variant) {
                    foreach ($this->prices()->historyOf($variant) as $price) {
                        $this->em->remove($price);
                    }
                    $this->em->remove($variant);
                }
                $this->em->remove($product);
            });
        } catch (ForeignKeyConstraintViolationException $e) {
            throw new ArticleInUseException($productId, $e);
        }
    }

    // ── the rules ────────────────────────────────────────────────────────

    /**
     * The validator of $draft against the data — for the service and for a dialog that shows the
     * rules of an UNSAVED form. $existing = the article being edited (its number and tax code
     * are its own).
     */
    public function validator(ArticleDraft $draft, ?Product $existing): ArticleValidator
    {
        $own   = $existing !== null ? $this->variants()->defaultOf($existing)?->getId() : null;
        $check = new LedgerAccountCheck($this->em);
        /** @var TaxCodeRepository $codes */
        $codes = $this->em->getRepository(TaxCode::class);

        return new ArticleValidator(
            $draft,
            numberTaken: function (string $number) use ($own): bool {
                $variant = $this->variants()->findByNumber($number);
                return $variant !== null && $variant->getId() !== $own;
            },
            taxCode: static fn(string $code): ?TaxCode => $codes->findByCode($code),
            accountProblem: static fn(string $number): ?string => self::accountProblem($check, $number),
            keptTaxCode: $existing?->getTaxCode() ?? '',
            group: fn(int $id): ?ProductGroup => $this->groups()->find($id),
            keptGroup: $existing !== null ? (int) $existing->getProductGroup()->getId() : 0,
        );
    }

    /**
     * The soft account check in German (the invoice line's wording, debtor.md): null = accepted
     * or not checkable (financial absent).
     */
    public static function accountProblem(LedgerAccountCheck $check, string $number): ?string
    {
        if ($check->isPostable($number) === false) {
            return 'Konto ' . $number . ' gibt es in der Buchhaltung nicht, es ist eine Gruppe oder inaktiv.';
        }
        if ($check->hasType($number, LedgerAccountCheck::TYPE_REVENUE) === false) {
            return 'Konto ' . $number . ' ist kein Ertragskonto — ein Artikel bucht nur auf ein Konto vom Typ «Ertrag» im Kontenplan.';
        }
        if ($check->isDocumentAccount($number) === false) {
            return 'Konto ' . $number . ' ist im Kontenplan nicht «auf Belegen wählbar» — ein anderes Ertragskonto wählen oder das Konto im Kontenplan freigeben.';
        }

        return null;
    }

    /**
     * A NEW article's empty tax code / revenue account from its group's defaults (nearest group
     * above that sets one). An unknown or unchosen group adds nothing — the validator refuses it.
     */
    private function withGroupDefaults(ArticleDraft $draft): ArticleDraft
    {
        if ($draft->productGroup <= 0 || ($draft->taxCode !== '' && $draft->revenueAccount !== '')) {
            return $draft;
        }
        $defaults = $this->groups()->tree()->defaultsFor($draft->productGroup);

        return $draft->withDefaults($defaults['taxCode'], $defaults['revenueAccount']);
    }

    /** The validated group of a draft. */
    private function group(int $id): ProductGroup
    {
        return $this->groups()->find($id) ?? throw new \LogicException('The product group was not validated');
    }

    /** @throws InvalidArticleException */
    private function assertValid(ArticleDraft $draft, ?Product $existing): void
    {
        $validator = $this->validator($draft, $existing);
        if (!$validator->isValid()) {
            throw new InvalidArticleException($validator, $draft);
        }
    }

    /** @return array{0: Money, 1: PriceMode, 2: \DateTimeImmutable} the validated price, mode and day */
    private function priceOf(ArticleDraft $draft): array
    {
        return [
            ArticleValidator::price($draft, self::currency()),
            PriceMode::from($draft->priceMode),
            ArticleValidator::parseDate($draft->validFrom) ?? throw new \LogicException('The day was not validated'),
        ];
    }

    /** A4: that day's row is replaced; else a new row when the price valid that day differs. */
    private function writePrice(Variant $variant, \DateTimeImmutable $day, Money $amount, PriceMode $mode): void
    {
        $sameDay = $this->prices()->onDay($variant, $day);
        if ($sameDay !== null) {
            if (!$sameDay->says($amount, $mode)) {
                $sameDay->replace($amount, $mode);
            }
            return;
        }
        $valid = $this->prices()->validAt($variant, $day);
        if ($valid !== null && $valid->says($amount, $mode)) {
            return;
        }
        $this->em->persist(new Price($variant, $day, $amount, $mode));
    }

    /** The unique index on the number decides under a race — mapped onto the field. */
    private function refuseNumberRace(ArticleDraft $draft, callable $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), 'uniq_article_variant_number')) {
                throw $e;
            }
            $validator = new ArticleValidator($draft);
            $validator->flagFieldError('number', 'Die Artikelnummer «' . $draft->number . '» wurde soeben vergeben — eine andere wählen.');
            throw new InvalidArticleException($validator, $draft);
        }
    }

    private function products(): ProductRepository
    {
        return $this->em->getRepository(Product::class);
    }

    private function variants(): VariantRepository
    {
        return $this->em->getRepository(Variant::class);
    }

    private function groups(): ProductGroupRepository
    {
        return $this->em->getRepository(ProductGroup::class);
    }

    private function prices(): PriceRepository
    {
        return $this->em->getRepository(Price::class);
    }
}
