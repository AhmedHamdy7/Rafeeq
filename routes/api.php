<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /v1
|--------------------------------------------------------------------------
|
| Versioned per D-decisions in RAFEEQ_MASTER_PLAN.md §15.4: /v1 must never
| receive a breaking change. Endpoints are added domain by domain starting
| Phase 2 (auth). This file is intentionally empty of routes for now.
|
*/

Route::prefix('v1')->group(function (): void {
    //
});
