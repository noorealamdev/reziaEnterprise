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
        // Production runs behind Coolify's reverse proxy (Traefik), which
        // terminates HTTPS and forwards to this container over plain HTTP.
        // Without this, url()/route() and secure cookies would think every
        // request is HTTP, since only the proxy itself sees Coolify's real
        // domain — trusting all proxies is standard for this deployment
        // shape (the proxy sits on the private Docker network, not the
        // public internet reaching the app directly).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
