<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Enums\SupportTicketPriority;
use App\Domains\Admin\Enums\SupportTicketStatus;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\SupportTicket;
use App\Livewire\Admin\Support;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Dashboard phase 10: Support cases (X).
 */
it('lists open cases, the most urgent first', function () {
    SupportTicket::factory()->create(['priority' => SupportTicketPriority::Low]);
    $urgent = SupportTicket::factory()->create(['priority' => SupportTicketPriority::Urgent]);

    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Support::class)
        ->assertViewHas('tickets', fn ($page) => $page->total() === 2 && $page->first()->is($urgent));
});

it('takes a case, then resolves it with a note, each in the audit log', function () {
    $ticket = SupportTicket::factory()->create();
    $agent = adminWithRole(AdminRole::Support);
    actingAsAdmin($agent);

    Livewire::test(Support::class)
        ->call('take', $ticket->id)
        ->call('startFinish', $ticket->id, 'resolved')
        ->set('note', 'Explained the weekly settlement; she will pay Thursday.')
        ->call('finish')
        ->assertHasNoErrors();

    expect($ticket->refresh()->status)->toBe(SupportTicketStatus::Resolved)
        ->and($ticket->assigned_admin_id)->toBe($agent->id)
        ->and(AdminAction::query()->where('action', 'ticket.take')->exists())->toBeTrue()
        ->and(AdminAction::query()->where('action', 'ticket.resolve')->sole()->reason)->toContain('weekly settlement');
});

it('asks for a note before closing', function () {
    $ticket = SupportTicket::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Support::class)
        ->call('startFinish', $ticket->id, 'closed')
        ->set('note', '')
        ->call('finish')
        ->assertHasErrors('note');

    expect($ticket->refresh()->status)->toBe(SupportTicketStatus::Open);
});

it('will not take a case that is already closed', function () {
    $ticket = SupportTicket::factory()->create();
    $ticket->forceFill(['status' => SupportTicketStatus::Closed])->save();
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Support::class)->call('take', $ticket->id)->assertHasErrors('ticket');
});

it('lets a role that may read cases read them, and nothing else', function () {
    $ticket = SupportTicket::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead)); // ticket.view, not ticket.resolve

    Livewire::test(Support::class)->assertOk()->call('take', $ticket->id)->assertForbidden();

    actingAsAdmin(adminWithRole(AdminRole::Finance));
    $this->get(route('admin.support'))->assertForbidden();
});

it('has every support text in both languages', function () {
    $en = array_keys(Arr::dot(trans('admin/support', [], 'en')));
    $ar = array_keys(Arr::dot(trans('admin/support', [], 'ar')));

    expect($ar)->toEqualCanonicalizing($en);
});
