<?php

namespace App\Services;

use App\Domain\Auth\UserRepositoryInterface;
use App\Dto\AuthResultDto;
use App\Dto\Payload\LoginPayload;
use App\Dto\Payload\RegisterPayload;
use App\Dto\UserDto;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\RegistrationClosedException;

/**
 * Orchestrates authentication. No HTTP framework imports — failures are signalled with
 * domain exceptions mapped to status codes in bootstrap/app.php.
 */
class AuthService
{
    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function login(LoginPayload $payload): AuthResultDto
    {
        $userId = $this->users->verifyCredentials($payload->email, $payload->password);

        if ($userId === null) {
            throw new InvalidCredentialsException;
        }

        return new AuthResultDto(
            token: $this->users->issueToken($userId),
            user: $this->users->toDto($userId),
        );
    }

    public function register(RegisterPayload $payload): AuthResultDto
    {
        if ($this->users->anyUserExists()) {
            throw new RegistrationClosedException;
        }

        $userId = $this->users->createUser($payload);

        return new AuthResultDto(
            token: $this->users->issueToken($userId),
            user: $this->users->toDto($userId),
        );
    }

    public function me(int $userId): UserDto
    {
        return $this->users->toDto($userId);
    }

    public function logout(): void
    {
        $this->users->revokeCurrentToken();
    }
}
