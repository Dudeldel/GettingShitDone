<?php

namespace App\Domain\Auth;

use App\Dto\Payload\RegisterPayload;
use App\Dto\UserDto;

/**
 * Data-access contract for the single account. Implementations confine Eloquent +
 * Sanctum; no Model leaks past this boundary.
 */
interface UserRepositoryInterface
{
    public function anyUserExists(): bool;

    /**
     * Verify email + password; return the user id on success, null otherwise.
     */
    public function verifyCredentials(string $email, string $password): ?int;

    public function createUser(RegisterPayload $payload): int;

    /**
     * Issue a personal-access token for the user; return the plain-text token.
     */
    public function issueToken(int $userId): string;

    public function toDto(int $userId): UserDto;

    /**
     * Revoke the token used on the current request (no-op if none).
     */
    public function revokeCurrentToken(): void;
}
