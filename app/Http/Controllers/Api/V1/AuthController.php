<?php

namespace App\Http\Controllers\Api\V1;

use App\Dto\Payload\LoginPayload;
use App\Dto\Payload\RegisterPayload;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\RegistrationClosedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Authenticate and issue a bearer token.
     *
     * @unauthenticated
     *
     * @throws InvalidCredentialsException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->login(LoginPayload::fromArray($request->validated())),
            Response::HTTP_OK,
        );
    }

    /**
     * Register the first (and only) account. Closed once an account exists.
     *
     * @unauthenticated
     *
     * @throws RegistrationClosedException
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->register(RegisterPayload::fromArray($request->validated())),
            Response::HTTP_CREATED,
        );
    }

    /**
     * The authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return response()->json(
            $this->auth->me((int) $user->getAuthIdentifier()),
            Response::HTTP_OK,
        );
    }

    /**
     * Revoke the token used on this request.
     */
    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
