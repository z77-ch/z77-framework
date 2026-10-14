<?php

namespace Z77\Module\Article\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;

/**
 * The product entity — EMPTY on purpose (ADR-039 addendum 2026-10-08 (b)): every field and all
 * logic live in {@see AbstractProduct}. A project that needs columns of its own overrides THIS
 * class (same FQCN, under `override/z77/module/article/src/Entities/Product.php`), extends
 * `AbstractProduct`, repeats the attributes below and adds its columns with getters and
 * setters; the migration for them goes into `override/z77/project/res/migrations/`.
 *
 * Table `article_product` — the mapping IS the table definition; the module's migration
 * creates it identically, so `z77-db diff` sees no change.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'article_product')]
class Product extends AbstractProduct
{
}
