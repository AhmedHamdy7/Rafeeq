<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `/docs/api` and `/docs/api.json` — the OpenAPI document for the mobile team, served from a file.
 *
 * 🔴 Why not Scramble's own routes: they build the document per request, by static analysis of
 * every controller — 30+ seconds and 3 MB for this API. On a small instance that outlived PHP's
 * time limit, the response was cut off mid-stream (`ERR_HTTP2_PROTOCOL_ERROR` after `200 OK`), and
 * the docs page sat reloading. Scramble's own cache cannot help: Laravel refuses to unserialize
 * objects from the cache (`cache.serializable_classes = false`, a deliberate protection), so the
 * cached document came back as `__PHP_Incomplete_Class` and was rebuilt anyway.
 *
 * So the container writes the document once at start, in the background (docker/entrypoint.sh),
 * and these routes only ever read that file. Each deploy is a fresh container, so the file always
 * describes the code that is running.
 *
 * Access is unchanged: the same `RestrictedDocsAccess` middleware and `viewApiDocs` gate.
 */
final class ApiDocsController extends Controller
{
    public static function specPath(): string
    {
        return storage_path('app/openapi.json');
    }

    public function ui(): View
    {
        return view('api-docs', ['specUrl' => url('docs/api.json')]);
    }

    public function spec(Generator $generator): BinaryFileResponse|JsonResponse|Response
    {
        if (is_file(self::specPath())) {
            return response()->file(self::specPath(), [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache',
            ]);
        }

        // On a developer's machine there is no entrypoint: build it on the spot, as Scramble did.
        if (app()->environment('local', 'testing')) {
            return response()->json($generator->generate(Scramble::getGeneratorConfig('default'))->spec());
        }

        // Never built per request on a server — that is the timeout this class exists to avoid.
        return response()->json([
            'message' => 'The API document is being generated. Try again in a minute.',
        ], 503, ['Retry-After' => '30']);
    }
}
