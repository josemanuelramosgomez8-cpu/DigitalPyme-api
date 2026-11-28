<?php

use App\Http\Middleware\SecurityResponseHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityResponseHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof QueryException || $exception instanceof PDOException) {
                $isConnectionFailure = false;

                for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                    $code = (string) $cause->getCode();
                    $message = $cause->getMessage();
                    $errorInfo = $cause instanceof PDOException ? $cause->errorInfo : null;
                    $sqlState = is_array($errorInfo) ? (string) ($errorInfo[0] ?? '') : '';

                    if (
                        in_array($code, ['2002', '2006', '2013'], true)
                        || in_array((string) ($errorInfo[1] ?? ''), ['2002', '2006', '2013'], true)
                        || str_starts_with($sqlState, '08')
                        || preg_match('/SQLSTATE\\[HY000\\]\\s*\\[2002\\]/i', $message) === 1
                    ) {
                        $isConnectionFailure = true;
                        break;
                    }
                }

                return new JsonResponse([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible. Intenta nuevamente en unos momentos.',
                    'data' => [],
                ], $isConnectionFailure ? 503 : 500);
            }

            if (
                $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)
            ) {
                return null;
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Servicio temporalmente no disponible. Intenta nuevamente en unos momentos.',
                'data' => [],
            ], 500);
        });
    })->create();
