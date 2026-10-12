<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\ConfineExternalUsers;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            foreach (glob(__DIR__.'/../routes/modules/*.php') as $file) {
                Route::middleware(['web', 'auth'])->group($file);
            }
        },
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Identity/Console',
        __DIR__.'/../app/Modules/Audit/Console',
        __DIR__.'/../app/Modules/Workflow/Console',
        __DIR__.'/../app/Modules/EFile/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            ConfineExternalUsers::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Branded error pages for Inertia visits instead of the framework's.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if (! app()->environment('local', 'testing') || $request->expectsJson()) {
                if (in_array($status, [403, 404, 409, 500, 503], true) && ! $request->expectsJson()) {
                    return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
                }
            } elseif (in_array($status, [403, 404, 409], true) && $request->header('X-Inertia')) {
                return Inertia::render('Error', ['status' => $status, 'message' => $e instanceof AuthorizationException ? $e->getMessage() : null])
                    ->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
