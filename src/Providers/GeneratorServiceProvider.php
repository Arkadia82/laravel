<?php
/**
 * Created by Arkadia82.
 * User: Jordi Martínez
 * Email: jomasi1982@gmail.com
 */

namespace Arkadia\Laravel\Providers;

use Arkadia\Laravel\Commands\GenerateModelCommand;
use Arkadia\Laravel\Commands\GenerateMigrationsCommand;
use Arkadia\Laravel\Commands\GenerateBackpackCrudCommand;
use Arkadia\Laravel\Validators\UniqueValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModelCommand::class,
                GenerateMigrationsCommand::class,
                GenerateBackpackCrudCommand::class
            ]);
        }

        Validator::extend('unique_model', UniqueValidator::class, 'Value already exist in database');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
