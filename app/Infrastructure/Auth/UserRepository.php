<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\UserRepositoryInterface;
use App\Dto\Payload\RegisterPayload;
use App\Dto\UserDto;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserRepository implements UserRepositoryInterface
{
    public function anyUserExists(): bool
    {
        return User::query()->exists();
    }

    public function verifyCredentials(string $email, string $password): ?int
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        return (int) $user->getKey();
    }

    public function createUser(RegisterPayload $payload): int
    {
        $user = User::query()->create([
            'name' => $payload->name,
            'email' => $payload->email,
            'password' => $payload->password, // hashed by the model's 'password' cast
        ]);

        return (int) $user->getKey();
    }

    public function issueToken(int $userId): string
    {
        $user = User::query()->findOrFail($userId);

        return $user->createToken('spa')->plainTextToken;
    }

    public function toDto(int $userId): UserDto
    {
        $user = User::query()->findOrFail($userId);

        return new UserDto(
            id: (int) $user->getKey(),
            name: $user->name,
            email: $user->email,
        );
    }

    public function revokeCurrentToken(): void
    {
        // Resolve via the sanctum guard — the default (web) guard is null on a
        // token-authenticated request.
        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof User) {
            return;
        }

        // Token auth → currentAccessToken() is the request's PersonalAccessToken.
        $user->currentAccessToken()->delete();
    }
}
