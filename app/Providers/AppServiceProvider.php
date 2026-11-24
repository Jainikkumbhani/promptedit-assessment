<?php

namespace App\Providers;

use App\Billing\CreditLedger;
use App\Billing\PriceCalculator;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.api_key'),
            timeout: config('services.pixelforge.timeout'),
        ));

        $this->app->singleton(WebhookSignature::class, fn () => new WebhookSignature(
            secret: config('services.pixelforge.webhook_secret'),
            toleranceSeconds: config('services.pixelforge.webhook_tolerance'),
        ));

        $this->app->singleton(PriceCalculator::class, fn () => new PriceCalculator(
            markupPercent: config('billing.markup_percent'),
        ));
    }

    public function boot(): void
    {
        Gate::define('view-operator-report', fn (User $user) => $user->isAdmin());

        Event::listen(Registered::class, function (Registered $event) {
            $amount = Money::fromDecimal((string) config('billing.signup_credits_usd'));

            if ($amount->greaterThan(Money::zero())) {
                app(CreditLedger::class)->grantSignupBonus($event->user, $amount);
            }
        });
    }
}
