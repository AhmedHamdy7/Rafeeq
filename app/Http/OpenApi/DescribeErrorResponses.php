<?php

namespace App\Http\OpenApi;

use App\Domains\Shared\Support\ErrorCode;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionNamedType;

/**
 * Makes each operation's failure modes part of the contract, and corrects the
 * one thing Scramble infers wrongly about the success envelope.
 *
 * Two jobs, both about telling the mobile team the truth:
 *
 * 1. `meta` is optional. `ApiResponse::success()` omits the key entirely
 *    unless a paginated collection supplies it, but static analysis unions
 *    both branches and reports `meta: null` as always present. A client model
 *    generated from that would demand a key our responses never send.
 *
 * 2. Error responses exist. Scramble sees the controller's happy path only —
 *    every refusal in this system is a thrown `DomainException`, which never
 *    appears in a return type. Without this, the document would describe an
 *    API that cannot fail.
 */
final class DescribeErrorResponses implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $this->correctOptionalMeta($operation);

        foreach ($this->errorCodesFor($routeInfo) as $status => $codes) {
            $operation->addResponse($this->errorResponse($status, $codes));
        }
    }

    /**
     * Drops the phantom always-null `meta` from every success schema and
     * re-adds it as what it really is: an optional object, present only on
     * paginated collections.
     */
    private function correctOptionalMeta(Operation $operation): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response) {
                continue;
            }

            $type = $response->getContent('application/json')?->type;

            if (! $type instanceof ObjectType || ! $type->hasProperty('meta')) {
                continue;
            }

            $type->setRequired(array_values(array_filter(
                $type->required,
                fn (string $key) => $key !== 'meta',
            )));

            $type->addProperty('meta', (new ObjectType)
                ->nullable(true)
                ->setDescription('Pagination metadata (`page`, `total`). Present only on paginated collections — the key is absent otherwise.'));
        }
    }

    /**
     * What this endpoint can actually refuse with, grouped by HTTP status.
     *
     * Endpoint-specific codes are declared with `#[ApiErrors]`. The rest are
     * derived from facts about the route rather than guessed: a FormRequest
     * parameter means 422 is reachable, `auth:sanctum` means 401 is,
     * `account.active` means 403 is, and the exception handler's catch-all
     * means 500 always is.
     *
     * @return array<int, array<int, ErrorCode>>
     */
    private function errorCodesFor(RouteInfo $routeInfo): array
    {
        $codes = $this->declaredCodes($routeInfo);

        $middleware = $routeInfo->route->gatherMiddleware();

        if ($this->hasFormRequest($routeInfo)) {
            $codes[] = ErrorCode::ValidationFailed;
        }

        if (in_array('auth:sanctum', $middleware, true)) {
            $codes[] = ErrorCode::Unauthenticated;
        }

        if (in_array('account.active', $middleware, true)) {
            $codes[] = ErrorCode::AccountSuspended;
        }

        if (in_array('profile.complete', $middleware, true)) {
            $codes[] = ErrorCode::ProfileIncomplete;
        }

        $codes[] = ErrorCode::ServerError;

        $grouped = [];

        foreach (array_unique($codes, SORT_REGULAR) as $code) {
            $grouped[$code->defaultStatus()][] = $code;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<int, ErrorCode>
     */
    private function declaredCodes(RouteInfo $routeInfo): array
    {
        $method = $routeInfo->reflectionMethod();

        if ($method === null) {
            return [];
        }

        $codes = [];

        foreach ($method->getAttributes(ApiErrors::class) as $attribute) {
            $codes = [...$codes, ...$attribute->newInstance()->codes];
        }

        return $codes;
    }

    private function hasFormRequest(RouteInfo $routeInfo): bool
    {
        $method = $routeInfo->reflectionMethod();

        if ($method === null) {
            return false;
        }

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), FormRequest::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One response per status, listing every code that can arrive with it —
     * the client switches on `error.code`, so a bare status would leave it
     * unable to tell a wrong code from an expired one.
     *
     * @param  array<int, ErrorCode>  $codes
     */
    private function errorResponse(int $status, array $codes): Response
    {
        $values = array_map(fn (ErrorCode $code) => $code->value, $codes);
        sort($values);

        $error = (new ObjectType)
            ->addProperty('code', (new StringType)
                ->enum($values)
                ->setDescription('Branch on this, never on `message`.'))
            ->addProperty('message', (new StringType)
                ->setDescription('Localised per `Accept-Language`, and written to be shown to the person as-is.'))
            ->addProperty('fields', (new ObjectType)
                ->additionalProperties((new ArrayType)->setItems(new StringType))
                ->nullable(true)
                ->setDescription('Field name to its messages. `null` unless the error is about specific input.'))
            ->setRequired(['code', 'message', 'fields']);

        $schema = (new ObjectType)
            ->addProperty('success', (new BooleanType)->enum([false]))
            ->addProperty('error', $error)
            ->setRequired(['success', 'error']);

        $response = Response::make($status)
            ->setDescription(implode(' · ', $values))
            ->setContent('application/json', Schema::fromType($schema));

        // Without Retry-After the client has to invent a backoff, which is how
        // a rate limit turns into a thundering herd.
        if ($status === 429) {
            $response->addHeader('Retry-After', (new Header)
                ->setDescription('Seconds to wait before retrying.')
                ->setSchema(Schema::fromType(new StringType)));
        }

        return $response;
    }
}
