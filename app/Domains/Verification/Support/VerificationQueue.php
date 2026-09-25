<?php

namespace App\Domains\Verification\Support;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Collection;

/**
 * The review queue, shaped the way the dashboard's cards read it.
 *
 * The prototype's queue shows, per person: initials, name, role, how long they have
 * been waiting, a risk pill, and a short list of checks each marked ok / warn / bad
 * ("National ID — Name mismatch", "Licence — Expires in 12 days"). None of that is a
 * column; all of it is derived, and it is derived HERE rather than in a Livewire
 * component so the rule behind each mark can be read and tested in one place.
 *
 * 🔴 Oldest first, and that ordering is a promise to the person waiting, not a
 * preference. A queue sorted by anything else — risk, name, whatever a reviewer
 * clicked last — means somebody who submitted four hours ago can sit behind arrivals
 * indefinitely while the dashboard looks busy and correct.
 *
 * The risk mark exists to direct attention, never to decide. A `bad` mark says "read
 * this one carefully", and `ReviewVerificationAction` still requires a human verdict —
 * nothing in this class approves or refuses anything.
 */
final readonly class VerificationQueue
{
    /** Marks, in the prototype's own vocabulary. */
    public const string OK = 'ok';

    public const string WARN = 'warn';

    public const string BAD = 'bad';

    /**
     * Levels waiting for a decision, oldest submission first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function pending(int $limit = 50): Collection
    {
        return UserVerification::query()
            ->where('status', VerificationStatus::Pending->value)
            ->with(['user', 'documents'])
            // `submitted_at` is when the clock the reviewer is being measured against
            // started; `created_at` is when the row appeared, which can be much
            // earlier for a level somebody started and came back to.
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (UserVerification $verification) => self::card($verification));
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(UserVerification $verification): array
    {
        $user = $verification->user;

        return [
            'id' => $verification->id,
            'type' => $verification->type,
            // 🔒 The reviewer sees the full name — they are comparing it against a
            // document, which is the one job that legitimately needs it.
            'name' => $user->full_name,
            'initials' => self::initials((string) $user->full_name),
            'role' => $user->registered_role?->value,
            'submittedAt' => $verification->submitted_at,
            'attempt' => $verification->attempt_count,
            'checks' => self::checks($verification),
            'risk' => self::risk($verification),
        ];
    }

    /**
     * The worst mark among the checks becomes the card's risk pill. A single `bad`
     * makes the whole card `bad`, because the point of the pill is "does this need
     * attention" and averaging would hide exactly the row that does.
     */
    public static function risk(UserVerification $verification): string
    {
        $marks = array_column(self::checks($verification), 'mark');

        if (in_array(self::BAD, $marks, true)) {
            return self::BAD;
        }

        return in_array(self::WARN, $marks, true) ? self::WARN : self::OK;
    }

    /**
     * What the reviewer is being asked to look at, one line each.
     *
     * Every line is a FACT about the submission — which documents arrived, what the
     * scanner said, whether a licence is close to expiry. None of it is an opinion
     * about the person, and none of it is scored into a number: a reviewer reading
     * "Licence — expires in 12 days" can judge that better than any weighting we would
     * invent for it.
     *
     * @return array<int, array{label: string, value: string, mark: string}>
     */
    public static function checks(UserVerification $verification): array
    {
        $checks = [];

        foreach ($verification->documents as $document) {
            $checks[] = [
                'label' => ucfirst(str_replace('_', ' ', $document->kind->value)),
                'value' => $document->virus_scan_status->value === 'clean'
                    ? __('admin.queue.uploaded')
                    : __('admin.queue.scan_'.$document->virus_scan_status->value),
                'mark' => $document->virus_scan_status->value === 'clean' ? self::OK : self::BAD,
            ];
        }

        if ($checks === []) {
            // A submitted level with no documents should not be possible — submission
            // checks for them. Surfaced rather than shown as an empty card, because an
            // empty card invites an approval of nothing.
            $checks[] = [
                'label' => __('admin.queue.documents'),
                'value' => __('admin.queue.none_attached'),
                'mark' => self::BAD,
            ];
        }

        if ($verification->attempt_count > 1) {
            $checks[] = [
                'label' => __('admin.queue.attempts'),
                'value' => (string) $verification->attempt_count,
                // Repeated trips through review are worth a second look, not a
                // refusal: a blurry photo twice is ordinary.
                'mark' => self::WARN,
            ];
        }

        foreach (self::licenceCheck($verification) as $check) {
            $checks[] = $check;
        }

        return $checks;
    }

    /**
     * A driver's licence expiry, when the person being reviewed is a driver applicant.
     *
     * Included because it is the one fact that can make an otherwise perfect
     * application not worth approving — a licence expiring in days means the approval
     * lapses almost immediately, and `RecomputeTrustLevelAction` stops counting it
     * without anyone being told.
     *
     * @return array<int, array{label: string, value: string, mark: string}>
     */
    private static function licenceCheck(UserVerification $verification): array
    {
        $profile = DriverProfile::query()->whereKey($verification->user_id)->first();

        if ($profile?->licence_expiry === null) {
            return [];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($profile->licence_expiry, absolute: false);

        return [[
            'label' => __('admin.queue.licence'),
            'value' => $profile->licence_expiry->toDateString(),
            'mark' => match (true) {
                $daysLeft < 0 => self::BAD,
                $daysLeft <= 30 => self::WARN,
                default => self::OK,
            },
        ]];
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(
            fn (string $part) => mb_substr($part, 0, 1),
            array_slice($parts, 0, 2),
        )));
    }
}
