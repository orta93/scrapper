<?php

namespace Markerly\Scrapper\Providers;

use Illuminate\Support\ServiceProvider;

class ScrapperProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/src/Database/Migrations');

        $this->publishes([
            __DIR__.'/src/config/platforms.php' => config_path('platforms.php'),
        ]);
    }
}
