<?php

use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\ItemPersistenceException;
use App\Exceptions\RegistrationClosedException;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the api group so even auth/bootstrap failures carry a request_id.
        $middleware->prependToGroup('api', AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Map auth domain exceptions to HTTP status (keeps AuthService HTTP-free).
        $exceptions->render(
            fn (InvalidCredentialsException $e) => response()->json(
                ['message' => $e->getMessage()], Response::HTTP_UNAUTHORIZED,
            ),
        );
        $exceptions->render(
            fn (RegistrationClosedException $e) => response()->json(
                ['message' => $e->getMessage()], Response::HTTP_FORBIDDEN,
            ),
        );
        // Deliberately a fixed message: the exception itself carries only a SQLSTATE code,
        // and the response must not echo anything derived from the failed write.
        $exceptions->render(
            fn (ItemPersistenceException $e) => response()->json(
                ['message' => 'The item could not be saved. Please try again.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            ),
        );
    })->create();
