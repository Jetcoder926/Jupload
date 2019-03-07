<?php
namespace Jetcoder\Jupload;

use Illuminate\Support\ServiceProvider;

class UploadServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        $this->publishes([
            __DIR__ . '/config.php' => config_path('jupload.php'),
        ]);
    }

    protected $defer = true;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('jupload.save', function ($app) {
            return new Upload();
        });
    }

    public function provides()
    {
        return ['jupload.save'];
    }
}

