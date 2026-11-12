<?php

test('the bare domain opens the dashboard', function () {
    $this->get('/')->assertRedirect('/admin');

    // Signed out, the dashboard sends them to the sign-in page.
    $this->get('/admin')->assertRedirect(route('admin.login'));
});
