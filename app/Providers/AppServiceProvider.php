<?php

namespace App\Providers;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\CachingGeoEngine;
use App\Domains\Geo\Support\StraightLineGeoEngine;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Support\LogOtpSender;
use App\Domains\Verification\Contracts\VirusScanner;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\SignatureVirusScanner;
use App\Http\OpenApi\DescribeErrorResponses;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The SMS provider is still undecided (MASTER_PLAN §19, question #2).
        // Resolving by name keeps that decision to one config line and one
        // new class; `LogOtpSender` refuses to run in production itself, so
        // an unconfigured deploy fails loudly instead of losing every code.
        $this->app->bind(OtpSender::class, fn () => match (config('rafeeq.auth.otp.driver')) {
            'log' => new LogOtpSender,
            default => throw new InvalidArgumentException(
                'Unsupported OTP driver ['.config('rafeeq.auth.otp.driver').'].'
            ),
        });

        /*
         * Binding standard #7: every geographic question goes through this one
         * door, so a later move to PostGIS — or to a different provider — is a
         * change here and nowhere else. The caching decorator wraps whichever
         * engine is configured, because a provider is billed per call and the
         * same commute is looked up over and over.
         */
        $this->app->singleton(GeoQueryEngine::class, fn () => new CachingGeoEngine(
            match (config('rafeeq.geo.engine')) {
                'straight_line' => new StraightLineGeoEngine,
                default => throw new InvalidArgumentException(
                    'Unsupported geo engine ['.config('rafeeq.geo.engine').'].'
                ),
            }
        ));

        // Same shape, same reason: the real engine is not chosen yet, and
        // `SignatureVirusScanner` refuses to run in production so a deploy
        // without one fails rather than accepting unscanned uploads.
        $this->app->bind(VirusScanner::class, fn () => match (config('rafeeq.verification.virus_scanner')) {
            'signature' => new SignatureVirusScanner,
            default => throw new InvalidArgumentException(
                'Unsupported virus scanner ['.config('rafeeq.verification.virus_scanner').'].'
            ),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->composeAdminQueueCounts();

        // Engineering Bible §3.8 — enforced everywhere except production, where
        // a lazy-load or mass-assignment bug should degrade rather than 500.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Pitfall #8: mutable Carbon lets `$start->addDays(7)` silently mutate
        // $start. CarbonImmutable everywhere removes the whole bug class.
        Date::use(CarbonImmutable::class);

        // Models live under app/Domains/{Domain}/Models, not app/Models, so
        // Laravel's default nested-namespace factory guess never matches.
        // Every factory here is flat (database/factories/{Model}Factory)
        // with an explicit $model property, so basename resolution is enough
        // — no need to repeat newFactory() in every one of ~57 models.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // The OpenAPI document is a deliverable for the external Flutter team
        // (MASTER_PLAN §15.4/§18), so its accuracy is wired here rather than
        // left to whoever runs the export.
        Scramble::configure()
            ->withOperationTransformers(DescribeErrorResponses::class);
    }

    /**
     * The count badges on the dashboard's rail.
     *
     * A view composer rather than data passed from each Livewire component, because the
     * rail is drawn on every admin page and the alternative is the same two queries
     * copied into every component that will ever exist — where the first one to forget
     * them shows a rail with the badges silently missing.
     *
     * 🔒 Each count is gated on the same permission as the page it links to. A count is
     * small but it is still information: telling a Finance admin that 24 people are
     * waiting on identity review is telling them something about a queue they have no
     * business seeing.
     */
    private function composeAdminQueueCounts(): void
    {
        View::composer('components.layouts.admin', function ($view): void {
            $admin = auth('admin')->user();

            $view->with([
                'pendingVerifications' => $admin?->can(AdminPermission::VerificationView->value)
                    ? UserVerification::query()->where('status', VerificationStatus::Pending->value)->count()
                    : 0,

                'pendingDrivers' => $admin?->can(AdminPermission::DriverView->value)
                    ? DriverProfile::query()->where('status', DriverProfileStatus::PendingReview->value)->count()
                    : 0,
            ]);
        });
    }
}
