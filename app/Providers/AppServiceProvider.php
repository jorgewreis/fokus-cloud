<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep provider retries possible while bounding work from one source.
        RateLimiter::for('mercado-pago-webhook', static fn (Request $request): Limit =>
            Limit::perMinute((int) config('services.mercado_pago.webhook_rate_limit', 300))
                ->by('mercado-pago-webhook:'.$request->ip())
        );

        // The production schema uses MySQL's binary ASCII collation for
        // prefixed ULID identifiers. Register the equivalent comparator when
        // the feature suite runs against its isolated SQLite memory database.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->sqliteCreateCollation('ascii_bin', static fn (string $left, string $right): int => strcmp($left, $right));
        }
    }
}
