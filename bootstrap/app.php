<?php

use App\Exceptions\InvalidClarificationException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\ItemActionNotAllowedException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\ItemNotInInboxException;
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
        // The clarify failures, each a distinct thing the client must handle differently.
        // 404 and 409 must stay apart: a client that confuses "no such item" with "already
        // clarified" will retry forever. A clarified item is not stuck — it is re-filed through
        // /refile, which answers 422 rather than 409 when the destination is not legal.
        $exceptions->render(
            fn (ItemNotFoundException $e) => response()->json(
                ['message' => 'That item no longer exists.'], Response::HTTP_NOT_FOUND,
            ),
        );
        $exceptions->render(
            fn (ItemNotInInboxException $e) => response()->json(
                ['message' => 'That item has already been clarified.'], Response::HTTP_CONFLICT,
            ),
        );
        // The item exists and the verb exists; the two do not go together. 422 rather than
        // 409: nothing is in conflict, the request simply named an action that is not legal
        // where the item currently sits.
        $exceptions->render(
            fn (ItemActionNotAllowedException $e) => response()->json(
                ['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
        );
        $exceptions->render(
            fn (InvalidClarificationException $e) => response()->json(
                ['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
        );
    })->create();
