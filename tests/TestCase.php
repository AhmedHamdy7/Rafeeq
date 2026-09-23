<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test request starts with no resolved user, the way a real one
     * does.
     *
     * In production each request boots the application fresh, so a guard
     * never carries a user over from the previous request. Inside one test
     * the application is reused, and `RequestGuard` (which is what
     * `auth:sanctum` is) memoises the user it resolved — so a request made
     * after the token was deleted would still come back authenticated, and
     * a test asserting that logout or device revocation locks someone out
     * would pass for the wrong reason.
     *
     * Forgetting the guards here restores the real-world behaviour rather
     * than working around it. `json()` and friends all funnel through
     * `call()`, so this is the only place it is needed.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        // `Authenticate` middleware calls `shouldUse($guard)` on success, which
        // changes the DEFAULT guard for the rest of the process. In production
        // that dies with the request; inside one test it persists, so after any
        // authenticated call `$request->user()` would resolve a bearer token on
        // a route that has no auth middleware at all — and a test of an
        // unauthenticated path would silently exercise an authenticated one.
        $this->app['auth']->shouldUse(config('auth.defaults.guard'));

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
