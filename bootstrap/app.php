<?php

use App\Exceptions\AppException;
use App\Http\Middleware\CheckPermission;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Also mount API routes at the bare root so the FE works in BOTH:
        //   dev    : NEXT_PUBLIC_API_BASE_URL=http://localhost:8080  (no /api)
        //   prod   : NEXT_PUBLIC_API_BASE_URL=https://kite.furukawa.id/api
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful middleware is opt-in via /sanctum/csrf-cookie route.
        // For pure token-based access from the SPA we don't need it.

        $middleware->alias([
            'permission' => CheckPermission::class,
        ]);

        // The default redirect on auth failure goes to /login (web).
        // For API we always want JSON 401.
        $middleware->redirectGuestsTo(function (Request $request) {
            // Returning null here lets the AuthenticationException be rendered
            // by the exception handler below.
            return null;
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON for API requests in the envelope shape the FE expects.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*') && ! $request->is('*')) {
                return null;
            }

            return match (true) {
                $e instanceof AppException
                    => ApiResponse::error($e->getMessage(), $e->status(), $e->details()),

                $e instanceof ValidationException
                    => ApiResponse::error('Validation failed', 422, $e->errors()),

                $e instanceof AuthenticationException
                    => ApiResponse::error('Unauthenticated', 401),

                $e instanceof AuthorizationException
                    => ApiResponse::error($e->getMessage() ?: 'Forbidden', 403),

                $e instanceof ModelNotFoundException
                    => ApiResponse::error('Resource not found', 404),

                $e instanceof NotFoundHttpException
                    => ApiResponse::error('Route not found', 404),

                $e instanceof MethodNotAllowedHttpException
                    => ApiResponse::error('Method not allowed', 405),

                $e instanceof HttpExceptionInterface
                    => ApiResponse::error($e->getMessage() ?: 'HTTP error', $e->getStatusCode()),

                default => null, // fall through to Laravel's default rendering
            };
        });

        // Always JSON, never redirect, for unauthenticated.
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson() || $request->wantsJson();
        });
    })->create();
