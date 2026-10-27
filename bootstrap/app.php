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
        $middleware->alias([
            'company' => \App\Http\Middleware\EnsureCompanySelected::class,
        ]);
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);

        // The active company must be resolved before route-model binding so the
        // tenant scope applies to bound models (cross-company ids give 404).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureCompanySelected::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
