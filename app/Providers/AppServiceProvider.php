<?php

namespace App\Providers;

use App\Services\QueueTriggerService;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        spl_autoload_register(function (string $class): bool {
            $prefixes = [
                'Endroid\\QrCode\\' => base_path('vendor/endroid/qr-code/src/'),
                'BaconQrCode\\' => base_path('vendor/bacon/bacon-qr-code/src/'),
                'DASPRiD\\Enum\\' => base_path('vendor/dasprid/enum/src/'),
            ];

            foreach ($prefixes as $prefix => $baseDir) {
                if (str_starts_with($class, $prefix)) {
                    $relativeClass = substr($class, strlen($prefix));
                    $file = $baseDir.str_replace('\\', '/', $relativeClass).'.php';
                    if (file_exists($file)) {
                        require_once $file;

                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        if ($appUrl = config('app.url')) {
            URL::forceRootUrl($appUrl);
            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }

        Event::listen(JobQueued::class, function (): void {
            QueueTriggerService::trigger();
        });
    }
}
