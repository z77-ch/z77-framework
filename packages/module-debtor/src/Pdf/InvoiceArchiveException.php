<?php

namespace Z77\Module\Debtor\Pdf;

/**
 * The archived PDF of an issued document version is missing or does not match its recorded
 * SHA-256 ({@see InvoiceArchive::pdf()}, ADR-054 decision 25). The message is German, for the
 * screen; the technical cause is in the error log. Never answered with a re-rendered PDF.
 */
final class InvoiceArchiveException extends \RuntimeException
{
}
