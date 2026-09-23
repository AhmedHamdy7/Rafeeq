<?php

namespace App\Providers;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Support\LogOtpSender;
use App\Http\OpenApi\DescribeErrorResponses;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
}
