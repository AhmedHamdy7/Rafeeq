<?php

use App\Domains\Admin\Enums\AdminRole;

test('the bare domain opens the dashboard', function () {
    $this->get('/')->assertRedirect('/admin');

    // Signed out, the dashboard sends them to the sign-in page.
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

test('a signed-in admin opening the sign-in page goes to the dashboard, not round in a circle', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.login'))->assertRedirect(route('admin.home'));
});
