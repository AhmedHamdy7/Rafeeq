<?php

namespace App\Http\Middleware;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTestingApiIsOn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TestAccounts::apiEnabled()) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return $next($request);
    }
}
