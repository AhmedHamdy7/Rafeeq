<?php

namespace App\Domains\Commute\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ChangeApprovalResponse;
use Database\Factories\ChangeApprovalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One affected passenger's answer to a proposed change (Feature 03 v5 §6).
 *
 * 🔴 A row here is an ANSWER. There is no row meaning "has not replied" — v5: *"If no reply,
 * nothing changes"* — so the absence of a row is the silence, and nothing has to be written when a
 * proposal goes out. See {@see ChangeProposal::isUnanimous()}.
 *
 * `proposal_version` is what makes a revision reset the approvals without erasing them: an answer
 * to version 1 stops counting the moment the driver sends version 2, and is still there to show
 * her that this passenger had agreed before she changed it.
 */
class ChangeApproval extends Model
{
    /** @use HasFactory<ChangeApprovalFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'response' => ChangeApprovalResponse::class,
            'proposal_version' => 'integer',
            'responded_at' => 'datetime',
        ];
    }

    public function changeProposal(): BelongsTo
    {
        return $this->belongsTo(ChangeProposal::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
