<?php

use App\Domain\Auth\UserRepositoryInterface;
use App\Dto\Payload\RegisterPayload;
use App\Dto\UserDto;
use App\Exceptions\RegistrationClosedException;
use App\Services\AuthService;

/**
 * Hand-rolled fake repository — keeps this a true unit test (no app, no DB, no Mockery
 * lifecycle). The atomic gate lives in createFirstUserOrNull (returns null when an
 * account already exists); the Service maps null -> RegistrationClosedException.
 */
function fakeUserRepository(bool $accountExists): UserRepositoryInterface
{
    return new class($accountExists) implements UserRepositoryInterface
    {
        public function __construct(private bool $exists) {}

        public function verifyCredentials(string $email, string $password): ?int
        {
            return null;
        }

        public function createFirstUserOrNull(RegisterPayload $payload): ?int
        {
            return $this->exists ? null : 1;
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

it('refuses to register when an account already exists', function () {
    $service = new AuthService(fakeUserRepository(accountExists: true));

    $service->register(new RegisterPayload('Owner', 'owner@example.com', 'password123'));
})->throws(RegistrationClosedException::class);

it('issues a token + user when registering the first account', function () {
    $service = new AuthService(fakeUserRepository(accountExists: false));

    $result = $service->register(new RegisterPayload('Owner', 'owner@example.com', 'password123'));

    expect($result->token)->toBe('token')
        ->and($result->user)->toBeInstanceOf(UserDto::class);
});
