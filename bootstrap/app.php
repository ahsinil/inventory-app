<?php

use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ServiceAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(AddRequestId::class);
        $middleware->alias([
            'service.auth' => ServiceAuth::class,
            'idempotent' => Idempotency::class,
        ]);
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = 500;
                $code = 'INTERNAL_ERROR';
                $errors = [];

                if ($e instanceof ValidationException) {
                    $status = $e->status;
                    $code = 'VALIDATION_ERROR';
                    $errors = $e->errors();
                } elseif ($e instanceof AuthenticationException) {
                    $status = 401;
                    $code = 'UNAUTHENTICATED';
                } elseif ($e instanceof AuthorizationException) {
                    $status = 403;
                    $code = 'UNAUTHORIZED';
                } elseif ($e instanceof NotFoundHttpException) {
                    $status = 404;
                    $code = 'NOT_FOUND';
                } elseif ($e instanceof ModelNotFoundException) {
                    $status = 404;
                    $code = 'NOT_FOUND';
                } elseif ($e instanceof HttpException) {
                    $status = $e->getStatusCode();
                    $code = 'HTTP_ERROR';
                } elseif ($e instanceof DomainException || $e instanceof LogicException) {
                    $status = 422;
                    $code = 'BUSINESS_LOGIC_ERROR';
                }

                return response()->json([
                    'message' => $e->getMessage() ?: 'Server Error',
                    'code' => $code,
                    'errors' => empty($errors) ? (object) [] : $errors,
                ], $status);
            }
        });
    })->create();
