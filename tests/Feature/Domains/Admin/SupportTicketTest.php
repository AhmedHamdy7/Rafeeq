<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\SupportTicket;

it('assigns a ticket to an admin', function () {
    $admin = AdminUser::factory()->create();
    $ticket = SupportTicket::factory()->create(['assigned_admin_id' => $admin->id]);

    expect($ticket->assignedAdmin->id)->toBe($admin->id)
        ->and($admin->assignedTickets)->toHaveCount(1);
});
