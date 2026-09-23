<?php

namespace App\Http\OpenApi;

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

/**
 * Marks every `auth:sanctum` route as requiring a bearer token, and every
 * public one as explicitly requiring nothing.
 *
 * The base strategy derives that from route middleware, which is the only
 * source that cannot drift: a route's protection IS its middleware, so the
 * document can never claim an endpoint is public when it is not.
 *
 * This exists as a class rather than as the `[class, options]` config array
 * the package documents because that form puts a `SecurityScheme` OBJECT in
 * the config file, and `config:cache` serialises config with `var_export` —
 * which cannot express an object. The documented form therefore works until
 * the first production deploy caches its config, and then breaks. Passing
 * the scheme in a constructor keeps `config/scramble.php` to a class string.
 *
 * The description is where the refresh-rotation rule is spelled out, because
 * it is the one part of this API a client can get wrong in a way that locks
 * a real person out of their own account.
 */
final class BearerTokenSecurity extends MiddlewareAuthSecurityStrategy
{
    public function __construct()
    {
        parent::__construct(
            middleware: ['auth', 'auth:*'],
            scheme: SecurityScheme::http('bearer')
                ->setDescription(<<<'MARKDOWN'
                The short-lived **access token** returned by
                `POST /v1/auth/otp/verify` or `POST /v1/auth/session/refresh`.

                When it expires, call `POST /v1/auth/session/refresh` with the
                refresh token. That call returns a NEW refresh token and
                invalidates the one you sent: refresh tokens are single-use.

                Never retry a refresh with a token that already succeeded. A
                second use is indistinguishable from a captured token, so the
                server revokes every session in that token's family and the
                person has to verify their phone again.
                MARKDOWN)
                ->as('bearerAuth'),
        );
    }
}
