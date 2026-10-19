<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ModulesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $modulePaths = glob(base_path('Modules/*'), GLOB_ONLYDIR) ?: [];

        foreach ($modulePaths as $modulePath) {
            $moduleName = basename($modulePath);
            $viewPath = $modulePath.DIRECTORY_SEPARATOR.'Views';
            $routeFile = $modulePath.DIRECTORY_SEPARATOR.'route.php';

            if (is_dir($viewPath)) {
                $this->loadViewsFrom($viewPath, Str::kebab($moduleName));
            }

            if (! $this->app->routesAreCached() && is_file($routeFile)) {
                Route::middleware('web')->group($routeFile);
            }
        }
    }
}
