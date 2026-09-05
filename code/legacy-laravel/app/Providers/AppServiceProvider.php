<?php

namespace App\Providers;

use App\Contracts\ProcessRunner;
use App\Services\System\SymfonyProcessRunner;
use App\Services\Updates\ApplicationUpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Native\Desktop\Events\AutoUpdater\CheckingForUpdate;
use Native\Desktop\Events\AutoUpdater\DownloadProgress;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApplicationUpdater();
    }

    private function configureApplicationUpdater(): void
    {
        Event::listen(CheckingForUpdate::class, fn () => app(ApplicationUpdateStatus::class)
            ->set('checking', 'Checking the release feed for a newer version...'));
        Event::listen(UpdateAvailable::class, fn (UpdateAvailable $event) => app(ApplicationUpdateStatus::class)
            ->set('downloading', "Version {$event->version} is available and downloading.", $event->version, 0));
        Event::listen(DownloadProgress::class, fn (DownloadProgress $event) => app(ApplicationUpdateStatus::class)
            ->set('downloading', 'Downloading the application update...', null, $event->percent));
        Event::listen(UpdateDownloaded::class, fn (UpdateDownloaded $event) => app(ApplicationUpdateStatus::class)
            ->set('downloaded', "Version {$event->version} is ready to install.", $event->version, 100));
        Event::listen(UpdateNotAvailable::class, fn (UpdateNotAvailable $event) => app(ApplicationUpdateStatus::class)
            ->set('current', 'VisualBuilder is up to date.', $event->version));
        Event::listen(UpdateError::class, fn (UpdateError $event) => app(ApplicationUpdateStatus::class)
            ->set('error', $event->message));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
