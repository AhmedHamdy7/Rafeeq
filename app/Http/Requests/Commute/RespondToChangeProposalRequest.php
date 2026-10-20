<?php

namespace App\Http\Requests\Commute;

use App\Domains\Commute\Enums\ChangeApprovalResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A passenger's answer to a proposed change (Feature 03 v5 §6).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - 🔴 **There is nothing to partially accept.** v5: `Accept All Changes` / `Reject`, and "Change
 *   proposal partial acceptance never applies". So the payload is one word, and there is
 *   deliberately no field for agreeing to the departure but not the pickup.
 * - `withdrawn` is for taking an acceptance back before the change is applied, which v5 allows
 *   explicitly. It is kept distinct from `rejected`: one is "no", the other is "I had said yes".
 * - Which booking she is answering for is settled by the controller from her own id. Accepting it
 *   from the payload would let anybody answer for anybody.
 */
final class RespondToChangeProposalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `accepted` · `rejected` · `withdrawn` (take back an acceptance before it applies).
            'response' => ['required', 'string', Rule::enum(ChangeApprovalResponse::class)],
        ];
    }
}
