<?php

namespace App\Services;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class QueueTriggerService
{
    /**
     * Trigger a background queue worker to process jobs immediately
     * without blocking the HTTP request.
     */
    public static function trigger(): void
    {
        // Never trigger in test environments or unit tests
        if (App::runningUnitTests() || App::environment('testing')) {
            return;
        }

        // Only trigger if auto-trigger is explicitly enabled (disabled on production with Supervisor)
        if (! Config::get('queue.auto_trigger', true)) {
            return;
        }

        // Only trigger if using the database queue driver
        if (Config::get('queue.default') !== 'database') {
            return;
        }

        // Fallback: register terminating callback to run any remaining jobs after response is sent
        app()->terminating(function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            try {
                if (Config::get('queue.default') === 'database' && DB::table('jobs')->count() > 0) {
                    Artisan::call('queue:work', [
                        '--once' => true,
                        '--stop-when-empty' => true,
                        '--timeout' => 60,
                    ]);
                }
            } catch (\Throwable) {
                // Ignore any failure in terminating worker
            }
        });

        // Debounce: don't spawn multiple workers within 2 seconds
        try {
            if (Cache::has('poster_bg_worker_triggered')) {
                return;
            }
            Cache::put('poster_bg_worker_triggered', true, 2);
        } catch (\Throwable) {
            // If cache is unavailable, proceed anyway
        }

        self::spawnWorker();
    }

    /**
     * Spawn a detached, non-blocking background queue worker.
     */
    public static function spawnWorker(): void
    {
        $php = self::findPhpBinary();
        $artisan = base_path('artisan');

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: "start "" /B ..." launches a detached background process with no window
            if (self::isFunctionEnabled('popen')) {
                $cmd = sprintf(
                    'start "" /B "%s" "%s" queue:work database --stop-when-empty --sleep=1 --timeout=90 --tries=1 > NUL 2>&1',
                    $php,
                    $artisan
                );
                @pclose(popen($cmd, 'r'));

                return;
            }
        } elseif (self::isFunctionEnabled('exec')) {
            // Unix / Linux: fork with &
            $cmd = sprintf(
                '"%s" "%s" queue:work database --stop-when-empty --sleep=1 --timeout=90 --tries=1 > /dev/null 2>&1 &',
                $php,
                $artisan
            );
            @exec($cmd);

            return;
        }

        // Web-based non-blocking cURL fallback when shell execution is disabled in php.ini
        if (function_exists('curl_init') && ($appUrl = Config::get('app.url'))) {
            $workerUrl = rtrim($appUrl, '/').'/queue/work-trigger';
            $ch = curl_init($workerUrl);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            @curl_exec($ch);
            @curl_close($ch);
        }
    }

    /**
     * Locate the PHP CLI executable, avoiding php-fpm / php-cgi when running in web SAPI.
     */
    protected static function findPhpBinary(): string
    {
        $laragonPhp = 'C:\\laragon\\bin\\php\\php-8.3.16-Win32-vs16-x64\\php.exe';
        if (file_exists($laragonPhp)) {
            return $laragonPhp;
        }

        $binary = PHP_BINARY ?: 'php';

        if (str_contains($binary, 'fpm') || str_contains($binary, 'cgi')) {
            $cliCandidate = defined('PHP_BINDIR') ? PHP_BINDIR.DIRECTORY_SEPARATOR.'php' : 'php';
            if (@file_exists($cliCandidate)) {
                return $cliCandidate;
            }

            return 'php';
        }

        return $binary;
    }

    /**
     * Check if a function exists and is not blocked by disable_functions.
     */
    protected static function isFunctionEnabled(string $name): bool
    {
        if (! function_exists($name)) {
            return false;
        }

        $disabled = explode(',', (string) ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        return ! in_array($name, $disabled, true);
    }
}
