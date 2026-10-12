<?php

namespace App\Providers;

use App\Contracts\EventPublisher;
use App\Services\Integration\LogEventPublisher;
use App\Services\Integration\RabbitMqEventPublisher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EventPublisher::class, fn () => config('integration.publisher') === 'rabbitmq'
            ? new RabbitMqEventPublisher
            : new LogEventPublisher);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limiting per client type
        RateLimiter::for('internal', fn (Request $r) => Limit::perMinute(config('integration.rate_limits.internal'))
            ->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('external', function (Request $r) {
            $cred = $r->attributes->get('service_credential');

            return Limit::perMinute(config('integration.rate_limits.external'))->by($cred?->id ?: $r->ip());
        });
    }
}
