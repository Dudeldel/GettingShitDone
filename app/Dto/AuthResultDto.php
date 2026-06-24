<?php

namespace App\Dto;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
class AuthResultDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $token,
        public readonly UserDto $user,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromArray(array $result): self
    {
        /** @var array<string, mixed> $user */
        $user = $result['user'];

        return new self(
            token: (string) $result['token'],
            user: UserDto::fromArray($user),
        );
    }

    /**
     * @return array{token: string, user: array{id: int, name: string, email: string}}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'user' => $this->user->toArray(),
        ];
    }

    /**
     * @return array{token: string, user: array{id: int, name: string, email: string}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
