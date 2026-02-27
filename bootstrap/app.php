<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->trustHosts(
            at: static function (): array {
                $configuredHosts = [
                    parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
                    env('APP_TRUSTED_HOST'),
                ];

                $hosts = [];
                foreach ($configuredHosts as $host) {
                    if (is_string($host) && $host !== '') {
                        $hosts[] = '^'.preg_quote($host).'$';
                    }
                }

                return array_values(array_unique($hosts));
            },
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'app_secret',
            'cookie_header',
            'curl_command',
        ]);
    })->create();
