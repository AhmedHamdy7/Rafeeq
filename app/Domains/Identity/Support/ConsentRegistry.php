<?php

namespace App\Domains\Identity\Support;

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Identity\Enums\ConsentDocumentType;

/**
 * Which version of each legal document is currently in force.
 *
 * Consent is stored per (document, version) rather than as a boolean
 * precisely so that publishing new terms can require a fresh acceptance
 * instead of silently relying on one given to a document nobody can produce
 * any more. Marketing is excluded from the required set: it is opt-in, and
 * withholding it must never block sign-in.
 */
final class ConsentRegistry
{
    /**
     * @return array<int, ConsentDocumentType>
     */
    public static function requiredDocuments(): array
    {
        return [ConsentDocumentType::Terms, ConsentDocumentType::Privacy];
    }

    public static function currentVersion(ConsentDocumentType $document): string
    {
        $key = match ($document) {
            ConsentDocumentType::Terms => 'legal.terms_version',
            ConsentDocumentType::Privacy => 'legal.privacy_version',
            // Marketing consent has no published document to version; the
            // acceptance itself is the whole record.
            ConsentDocumentType::Marketing => null,
        };

        if ($key === null) {
            return 'n/a';
        }

        return (string) PlatformSetting::value($key, config("rafeeq.{$key}"));
    }

    /**
     * @return array<string, string> document type => version in force
     */
    public static function currentVersions(): array
    {
        $versions = [];

        foreach (self::requiredDocuments() as $document) {
            $versions[$document->value] = self::currentVersion($document);
        }

        return $versions;
    }
}
