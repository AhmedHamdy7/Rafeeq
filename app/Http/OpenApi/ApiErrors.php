<?php

namespace App\Http\OpenApi;

use App\Domains\Shared\Support\ErrorCode;
use Attribute;

/**
 * Declares the domain errors an endpoint can answer with, so they appear in
 * the OpenAPI document instead of being discovered in production by the
 * mobile team.
 *
 * Declared rather than inferred: the codes are thrown deep in the Action
 * layer, and any static guess at "which errors can reach this route" would
 * be either wrong or so broad it tells the reader nothing. Listing them at
 * the entry point makes the contract a deliberate statement — and
 * `OpenApiDocumentTest` holds it honest by failing when a code exists that
 * no endpoint documents.
 *
 * The envelope-level errors that apply to whole classes of route
 * (validation, authentication, authorisation, the catch-all 500) are added
 * automatically from the route's own middleware and request class — see
 * {@see DescribeErrorResponses}. Only list what is specific to this
 * endpoint.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiErrors
{
    /** @var array<int, ErrorCode> */
    public readonly array $codes;

    public function __construct(ErrorCode ...$codes)
    {
        $this->codes = $codes;
    }
}
