<?php

namespace SobhanDev\DbGenerator\Providers;

use Illuminate\Support\ServiceProvider;

class DbGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register the command
        $this->commands([
            \SobhanDev\DbGenerator\Commands\DbGeneratorCommand::class,
        ]);
    }

    public function boot()
    {
        // Publish assets or config files if needed
    }
}