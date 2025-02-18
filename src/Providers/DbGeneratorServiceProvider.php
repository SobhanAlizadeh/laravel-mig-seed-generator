<?php

namespace YourVendorName\DbGenerator\Providers;

use Illuminate\Support\ServiceProvider;
use SobhanDev\DbGenerator\Commands\GenerateMigrationsAndSeeders;

class DbGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register the command
        $this->commands([
            GenerateMigrationsAndSeeders::class,
        ]);
    }

    public function boot()
    {
        // Publish assets or config files if needed
    }
}