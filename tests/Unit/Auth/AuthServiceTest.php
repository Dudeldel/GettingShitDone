<?php

use App\Domain\Auth\UserRepositoryInterface;
use App\Dto\Payload\RegisterPayload;
use App\Dto\UserDto;
use App\Exceptions\RegistrationClosedException;
use App\Services\AuthService;

/**
 * Hand-rolled fake repository — keeps this a true unit test (no app, no DB, no Mockery
 * lifecycle). The register gate is proven by the exception type: the closed case throws
 * RegistrationClosedException before createUser is reached.
 */
function fakeUserRepository(bool $anyUserExists): UserRepositoryInterface
{
    return new class($anyUserExists) implements UserRepositoryInterface
    {
        public function __construct(private bool $exists) {}

        public function anyUserExists(): bool
        {
            return $this->exists;
        }

        public function verifyCredentials(string $email, string $password): ?int
        {
            return null;
        }

        public function createUser(RegisterPayload $payload): int
        {
            return 1;
        }

        public function issueToken(int $userId): string
        {
            return 'token';
        }

        public function toDto(int $userId): UserDto
        {
            return new UserDto($userId, 'Name', 'name@example.com');
        }

        public function revokeCurrentToken(): void {}
    };
}

it('refuses to register when an account already exists, without creating a user', function () {
    $service = new AuthService(fakeUserRepository(anyUserExists: true));

    $service->register(new RegisterPayload('Owner', 'owner@example.com', 'password123'));
})->throws(RegistrationClosedException::class);

it('issues a token + user when registering the first account', function () {
    $service = new AuthService(fakeUserRepository(anyUserExists: false));

    $result = $service->register(new RegisterPayload('Owner', 'owner@example.com', 'password123'));

    expect($result->token)->toBe('token')
        ->and($result->user)->toBeInstanceOf(UserDto::class);
});
