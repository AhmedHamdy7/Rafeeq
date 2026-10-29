<?php

use App\Http\Controllers\Docs\ApiDocsController;

/**
 * `/docs/api` — served from a file the container writes at start, never built per request on a
 * server: building it takes 30+ seconds, and on Railway the response was cut off mid-stream
 * (`ERR_HTTP2_PROTOCOL_ERROR` after `200 OK`) while the page sat reloading.
 */
beforeEach(function () {
    $this->backup = is_file(ApiDocsController::specPath()) ? file_get_contents(ApiDocsController::specPath()) : null;
    config(['rafeeq.docs.public' => true]);
});

afterEach(function () {
    if ($this->backup !== null) {
        file_put_contents(ApiDocsController::specPath(), $this->backup);
    } elseif (is_file(ApiDocsController::specPath())) {
        unlink(ApiDocsController::specPath());
    }
});

it('serves the document from the file the container wrote', function () {
    file_put_contents(ApiDocsController::specPath(), '{"openapi":"3.1.0","info":{"title":"from the file"}}');
    app()->detectEnvironment(fn () => 'staging');

    $response = $this->get('/docs/api.json')->assertOk()->assertHeader('Content-Type', 'application/json');

    expect($response->baseResponse->getFile()->getPathname())->toBe(ApiDocsController::specPath());
});

it('answers 503 on a server while the file is not written yet, instead of building it', function () {
    @unlink(ApiDocsController::specPath());
    app()->detectEnvironment(fn () => 'staging');

    $this->get('/docs/api.json')->assertStatus(503)->assertHeader('Retry-After', '30');
});

it('loads the page without embedding the document', function () {
    app()->detectEnvironment(fn () => 'staging');

    $page = $this->get('/docs/api')->assertOk();

    expect($page->getContent())->toContain('apiDescriptionUrl="'.url('docs/api.json').'"')
        ->and(strlen($page->getContent()))->toBeLessThan(20_000);
});

it('stays closed in production whatever the switch says', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});
