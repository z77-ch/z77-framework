<?php

namespace Z77\Module\Article\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;

/**
 * The variant entity — EMPTY on purpose (ADR-039 addendum 2026-10-08 (b)): every field and all
 * logic live in {@see AbstractVariant}. A project override of THIS class repeats the attributes
 * below (the unique number included) and adds its columns.
 *
 * Table `article_variant` — the mapping IS the table definition; the module's migration creates
 * it identically. The article number is unique over all variants: the validator asks first, the
 * index decides under a race (`ArticleService` maps its violation onto the field).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'article_variant')]
#[ORM\UniqueConstraint(name: 'uniq_article_variant_number', columns: ['number'])]
class Variant extends AbstractVariant
{
}
