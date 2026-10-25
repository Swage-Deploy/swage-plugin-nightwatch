<?php

namespace Swage\Nightwatch;

use App\Sites\SiteIntegrations;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Swage\Nightwatch\Livewire\SiteSettings;

class NightwatchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nightwatch');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Livewire::component('nightwatch.site-settings', SiteSettings::class);

        $this->app->make(SiteIntegrations::class)->register(NightwatchIntegration::class);
    }
}
