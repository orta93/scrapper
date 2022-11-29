<?php

namespace Justinjkline\WebCrawlPublic\Providers;

use Illuminate\Support\ServiceProvider;

class WebCrawlPublicProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'\..\Database\Migrations');

        $this->publishes([
            __DIR__.'\..\config\platforms.php' => config_path('platforms.php'),
        ]);
    }
}
