<?php

namespace App\Http\Middleware;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Support\VerificationCentre;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server half of the `pendingIntent` pattern (Bible §5.1).
 *
 * The book's flow is: Mariam taps "request a seat", has only her phone
 * verified, so the app saves her intent, sends her to the Verification Centre,
 * and returns her to the same screen when she is done. For the app to do that
 * it needs more than a refusal — it needs to know exactly which levels are
 * missing, which is why the 403 names them in `error.fields` instead of just
 * saying no.
 *
 * Used as `verified:government_id` or `verified:government_id,selfie`.
 *
 * Applied per route, never globally: most of the API is reachable at level 1,
 * and gating everything would make the product unusable before a reviewer has
 * looked at anything.
 */
final class RequiresVerification
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $required = array_map(
            fn (string $type) => VerificationType::from($type),
            $types,
        );

        $missing = VerificationCentre::missingFrom($user, $required);

        if ($missing !== []) {
            // In `fields` because that is where the envelope already puts
            // machine-readable detail, so no client needs a second shape to
            // parse an error.
            throw DomainException::of(ErrorCode::VerificationRequired, fields: [
                'verification' => $missing,
            ]);
        }

        return $next($request);
    }
}
