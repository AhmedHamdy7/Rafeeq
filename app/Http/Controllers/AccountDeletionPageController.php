<?php

namespace App\Http\Controllers;

use App\Domains\Identity\Actions\DeleteAccountAction;
use App\Domains\Identity\Actions\RequestOtpAction;
use App\Domains\Identity\Actions\VerifyOtpAction;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * The web page Google Play asks for (F1-4): somebody who no longer has the app — or never could
 * open it again — can still delete her account, with nothing but her phone.
 *
 * Phone → code → delete, through the same three Actions the app uses: `RequestOtpAction` to text a
 * code, `VerifyOtpAction` to prove she holds the number, `DeleteAccountAction` (and so the same
 * debt and fee-window rules) to do it.
 *
 * 🔒 The code is issued for `high_risk_action`, never `authentication`: a code from this page
 * cannot sign anybody in, and a sign-in code cannot delete anybody. And the page never creates an
 * account — unlike the app's sign-in, verifying a number with no account here simply ends.
 */
final class AccountDeletionPageController extends Controller
{
    public function show(): Response
    {
        return $this->page('phone');
    }

    public function requestCode(Request $request, RequestOtpAction $otp): Response
    {
        $request->validate(['phone' => ['required', 'string', 'max:20']]);

        try {
            $phone = PhoneNumber::fromRaw((string) $request->input('phone'));
            $issued = $otp->execute($phone, OtpPurpose::HighRiskAction, $request->ip());
        } catch (InvalidArgumentException) {
            return $this->page('phone', error: __('errors.AUTH_PHONE_INVALID'));
        } catch (DomainException $e) {
            return $this->page('phone', error: __('errors.'.$e->errorCode->value));
        }

        $request->session()->put('account_deletion.challenge', $issued['challenge']->id);

        return $this->page('code', ['maskedPhone' => $issued['maskedPhone']]);
    }

    public function confirm(Request $request, VerifyOtpAction $verify, DeleteAccountAction $delete): Response
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);

        $challengeId = (string) $request->session()->get('account_deletion.challenge');

        try {
            $challenge = $verify->execute($challengeId, (string) $request->input('code'), [OtpPurpose::HighRiskAction]);
        } catch (DomainException $e) {
            // A wrong code may be retried; anything else (expired, too many tries) starts over.
            return $e->errorCode === ErrorCode::OtpInvalid
                ? $this->page('code', error: __('errors.'.$e->errorCode->value))
                : $this->page('phone', error: __('errors.'.$e->errorCode->value));
        }

        $request->session()->forget('account_deletion.challenge');

        $user = User::query()->where('phone_e164', VerifyOtpAction::phoneOf($challenge)->e164)->first();

        // She proved the number is hers, so saying it has no account tells nobody anything new.
        if ($user === null) {
            return $this->page('none');
        }

        try {
            $delete->execute($user);
        } catch (DomainException $e) {
            return $this->page('blocked', ['reason' => __('errors.'.$e->errorCode->value)]);
        }

        return $this->page('deleted');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $step, array $data = [], ?string $error = null): Response
    {
        return response()
            ->view('account-deletion', [...$data, 'step' => $step, 'error' => $error])
            ->header('X-Robots-Tag', 'noindex')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
