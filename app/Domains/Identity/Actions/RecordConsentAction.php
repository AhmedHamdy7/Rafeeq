<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\ConsentDocumentType;
use App\Domains\Identity\Enums\ConsentSource;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserConsent;
use App\Domains\Identity\Support\ConsentRegistry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes consent records — legal evidence, so they are appended rather than
 * edited (the `user_consents` FK is RESTRICT for the same reason). A person
 * accepting v2 does not erase the fact that they accepted v1; both rows
 * stand, each with its own timestamp.
 */
final readonly class RecordConsentAction
{
    /**
     * @param  array<int, ConsentDocumentType>|null  $documents  defaults to the
     *                                                           documents required to use Rafeeq at all
     */
    public function execute(User $user, ConsentSource $source, ?array $documents = null): Collection
    {
        $documents ??= ConsentRegistry::requiredDocuments();

        $recorded = new Collection;

        foreach ($documents as $document) {
            $version = ConsentRegistry::currentVersion($document);

            // Re-tapping accept on a version already on file must not create
            // a second row — it would make the audit trail read as if the
            // person was asked twice.
            $existing = UserConsent::query()
                ->where('user_id', $user->id)
                ->where('document_type', $document->value)
                ->where('document_version', $version)
                ->whereNull('withdrawn_at')
                ->first();

            $recorded->push($existing ?? UserConsent::create([
                'user_id' => $user->id,
                'document_type' => $document->value,
                'document_version' => $version,
                'accepted_at' => now(),
                'source' => $source->value,
            ]));
        }

        return $recorded;
    }

    /**
     * Document types whose CURRENT version this account has not accepted, as
     * their string values. Empty when nothing is owed. Drives the forced
     * re-acceptance prompt after new terms are published.
     *
     * Built with a foreach and an accumulator on purpose. The obvious
     * refactor — `array_map` over a filtered list — reads better but costs
     * the published API contract its element type: the OpenAPI generator
     * infers `array<string>` from this shape and only `array<unknown>` from
     * that one, which would leave the mobile team guessing at a field they
     * have to parse. `OpenApiDocumentTest` fails if that regresses.
     *
     * @return list<string>
     */
    public static function outstandingFor(User $user): array
    {
        $accepted = UserConsent::query()
            ->where('user_id', $user->id)
            ->whereNull('withdrawn_at')
            // Oldest first, so that when several versions of one document
            // are on file the pluck's last write — the newest acceptance —
            // is the one that survives keying by document type.
            ->orderBy('accepted_at')
            ->pluck('document_version', 'document_type')
            ->all();

        $outstanding = [];

        foreach (ConsentRegistry::requiredDocuments() as $document) {
            if (($accepted[$document->value] ?? null) !== ConsentRegistry::currentVersion($document)) {
                $outstanding[] = $document->value;
            }
        }

        return $outstanding;
    }
}
