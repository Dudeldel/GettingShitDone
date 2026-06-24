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
    /**
     * Verify email + password; return the user id on success, null otherwise.
     */
    public function verifyCredentials(string $email, string $password): ?int;

    /**
     * Atomically create the first account: returns the new user id, or null if an
     * account already exists. The check-and-create is serialized (transaction + lock)
     * so concurrent first-run registers cannot both succeed (single-account invariant).
     */
    public function createFirstUserOrNull(RegisterPayload $payload): ?int;

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
