<?php

namespace Z77\Persistence\Doctrine\OpenWork;

/**
 * How a finding of an open-work check weighs (plan §2, ADR-042 decision 11,
 * ADR-043 decision 17): `Blocking` stops the action the caller is about to
 * take (a period close, a stocktake); `Warning` is shown and lets it proceed.
 */
enum Severity
{
    case Blocking;
    case Warning;
}
