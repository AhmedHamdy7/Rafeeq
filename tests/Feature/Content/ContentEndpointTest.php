<?php

use App\Domains\Admin\Models\ContentPage;

/**
 * GET /v1/content/{key}: the texts staff write on the dashboard, for Help & legal.
 */
beforeEach(function () {
    $this->token = verifiedPassenger('01112229911');
});

it('returns a written text in both languages with its version', function () {
    ContentPage::factory()->create(['key' => 'support_contact', 'body_ar' => 'واتساب', 'body_en' => 'WhatsApp', 'version' => 3]);

    $this->withToken($this->token)->getJson('/api/v1/content/support_contact')
        ->assertOk()
        ->assertJsonPath('data.key', 'support_contact')
        ->assertJsonPath('data.version', 3)
        ->assertJsonPath('data.ar', 'واتساب')
        ->assertJsonPath('data.en', 'WhatsApp');
});

it('answers 404 for a text nobody has written, and for an unknown key', function () {
    $this->withToken($this->token)->getJson('/api/v1/content/terms')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->withToken($this->token)->getJson('/api/v1/content/secrets')->assertNotFound();
});

it('needs a signed-in member', function () {
    $this->getJson('/api/v1/content/help')->assertUnauthorized();
});
