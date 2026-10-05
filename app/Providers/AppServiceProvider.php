<?php

namespace App\Providers;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\LiveTripBoard;
use App\Domains\Admin\Support\MemberDirectory;
use App\Domains\Admin\Support\SafetyCaseQueue;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\CachingGeoEngine;
use App\Domains\Geo\Support\StraightLineGeoEngine;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Support\LogOtpSender;
use App\Domains\Notification\Contracts\PushSender;
use App\Domains\Notification\Support\ChatSettings;
use App\Domains\Notification\Support\LogPushSender;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Verification\Contracts\VirusScanner;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\SignatureVirusScanner;
use App\Http\OpenApi\DescribeErrorResponses;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // Push (Chapter 11). Only the development transport exists until a provider is chosen
        // and its credentials exist; see LogPushSender for why it does not refuse production.
        $this->app->bind(PushSender::class, fn () => match (config('rafeeq.notifications.push_driver')) {
            default => new LogPushSender,
        });

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

        $this->defineRateLimiters();
        $this->defineApiDocsAccess();

        // The OpenAPI document is a deliverable for the external Flutter team
        // (MASTER_PLAN §15.4/§18), so its accuracy is wired here rather than
        // left to whoever runs the export.
        Scramble::configure()
            ->withOperationTransformers(DescribeErrorResponses::class);
    }

    /**
     * 🔒 Who may read `/docs/api` — the gate `Scramble`'s `RestrictedDocsAccess` asks about.
     *
     * Without this gate defined, the docs are reachable in `local` and answer 403 everywhere else.
     * That default is right, and this is the deliberate exception to it: an external team cannot
     * build against a contract they are emailed a copy of after every change.
     *
     * 🔴 What opening it costs, stated plainly because the switch is one variable and somebody will
     * flip it again later: the OpenAPI document is the complete API surface — every endpoint, field
     * name and error code. It hands out no data and bypasses no check; it hands out a map, and a map
     * is the first thing somebody probing the platform would collect. On a staging instance holding
     * seeded fictional journeys that is a good trade. On one holding real journeys it is not.
     *
     * So production is excluded here rather than left to the variable. Any instance that is actually
     * serving people cannot have its contract opened by setting an environment flag, which is the
     * same reasoning as the development OTP code refusing to work there.
     */
    private function defineApiDocsAccess(): void
    {
        Gate::define('viewApiDocs', function (?object $user = null): bool {
            if ($this->app->isProduction()) {
                return false;
            }

            return (bool) config('rafeeq.docs.public');
        });
    }

    /**
     * Named limiters used by route middleware.
     */
    private function defineRateLimiters(): void
    {
        /*
         * Reports (Chapter 10 §Security: "prevent false incident spam", "rate-limit reports").
         *
         * 🔴 Deliberately generous, and the limit is read from settings so a safety team can change
         * it the first week they watch it. The failure to avoid here is refusing a REAL report:
         * somebody in a genuinely bad situation may file two or three in quick succession, and a
         * spurious one costs a human two minutes to read and close. Those two costs are nowhere near
         * equal, so the limit sits well above normal use and exists only to stop a script.
         *
         * Per user rather than per IP: a family sharing a connection must not throttle each other,
         * and the endpoint requires a token anyway.
         */
        // Trip chat: per sender. Enough for "I'm here / where are you", not enough to flood.
        RateLimiter::for('chat-messages', fn (Request $request) => Limit::perMinute(
            ChatSettings::messagesPerMinute()
        )->by($request->user()?->id ?? $request->ip()));

        RateLimiter::for('safety-reports', fn (Request $request) => Limit::perHour(
            SafetySettings::reportsPerHour()
        )->by($request->user()?->id ?? $request->ip()));
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
            $mayReadSafety = $admin?->can(AdminPermission::SafetyView->value) === true;

            $view->with([
                'pendingVerifications' => $admin?->can(AdminPermission::VerificationView->value)
                    ? UserVerification::query()->where('status', VerificationStatus::Pending->value)->count()
                    : 0,

                'pendingDrivers' => $admin?->can(AdminPermission::DriverView->value)
                    ? DriverProfile::query()->where('status', DriverProfileStatus::PendingReview->value)->count()
                    : 0,

                // Holds whose promised review time has passed — a member told "within 24h"
                // who is still waiting. The badge is the reminder.
                'overdueHolds' => $admin?->can(AdminPermission::MemberView->value)
                    ? MemberDirectory::overdueHolds()
                    : 0,

                'liveTrips' => $admin?->can(AdminPermission::TripView->value)
                    ? LiveTripBoard::count()
                    : 0,

                'openSafetyCases' => $mayReadSafety
                    ? SafetyCaseQueue::liveAlertCount() + SafetyCaseQueue::openReportCount()
                    : 0,

                /*
                 * 🔴 The banner across the top of EVERY page for anybody on the safety desk,
                 * not only on the safety page: an alert raised while the operator is reviewing
                 * a driver application must be on the screen they are already looking at. The
                 * prototype draws exactly this ("unassigned for 6 minutes").
                 *
                 * Not on the safety page itself. The layout is drawn once per navigation and
                 * Livewire's polling re-renders the page beneath it, so there the banner would
                 * keep insisting nobody had picked up an alert the operator just picked up.
                 * That page's own red cards say the same thing and stay current.
                 */
                'unacknowledgedAlert' => $mayReadSafety && ! request()->routeIs('admin.safety')
                    ? SafetyCaseQueue::oldestUnacknowledged()
                    : null,
            ]);
        });
    }
}
