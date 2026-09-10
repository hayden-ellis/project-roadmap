<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The browser writes this one itself (see partials/head), so it must
        // not go through the encrypter on the way back in.
        $middleware->encryptCookies(except: ['appearance']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
