<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtLedger;
use App\Livewire\Admin\Payments;
use Livewire\Livewire;

/**
 * PAYMENTS (ADM-03, ADM-04): confirming the InstaPay transfers members say they sent.
 */
beforeEach(function () {
    $this->member = User::factory()->create();
    DebtLedger::charge($this->member, DebtEntryType::Commission, 1_800, 'seat_commission', side: DebtSide::Driver);

    $this->payment = DebtPayment::factory()->create([
        'user_id' => $this->member->id,
        'side' => DebtSide::Driver,
        'amount_piastres' => 1_800,
    ]);
});

it('lets finance confirm a transfer, which takes it off the debt', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Payments::class)
        ->assertSee($this->payment->instapay_reference)
        ->call('confirm', $this->payment->id)
        ->assertHasNoErrors();

    expect($this->payment->refresh()->status)->toBe(DebtPaymentStatus::Confirmed)
        ->and(DebtLedger::owedBy($this->member, DebtSide::Driver))->toBe(0)
        ->and(AdminAction::query()->where('action', 'debt.payment_confirm')->exists())->toBeTrue();
});

it('refuses a transfer only with a reason, and takes nothing off', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(Payments::class)
        ->call('openRefuse', $this->payment->id)
        ->call('refuse', $this->payment->id)
        ->assertHasErrors('note')
        ->set('note', 'No transfer with this reference.')
        ->call('refuse', $this->payment->id)
        ->assertHasNoErrors();

    expect($this->payment->refresh()->status)->toBe(DebtPaymentStatus::Rejected)
        ->and(DebtLedger::owedBy($this->member, DebtSide::Driver))->toBe(1_800);
});

it('keeps the screen from staff without the money permissions', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Payments::class)->assertForbidden();
});
