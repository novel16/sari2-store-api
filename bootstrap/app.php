<?php

use App\Exceptions\CheckoutConflict;
use App\Http\Middleware\Admin\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureStoreMember;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->authenticateSessions();
        // PayMongo authenticates this exact public callback with its raw-body
        // signature. Browser-origin detection must not turn it into a CSRF route.
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/paymongo']);
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'store.member' => EnsureStoreMember::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => rtrim(config('app.frontend_url'), '/').'/login');
        $middleware->redirectUsersTo(fn (Request $request) => rtrim(config('app.frontend_url'), '/').'/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (CheckoutConflict $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => $exception->reason,
        ], 409));
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });
    })->create();
