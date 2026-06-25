<?php

namespace App\Dto;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
class UserDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}

    /**
     * @param  array<string, mixed>  $user
     */
    public static function fromArray(array $user): self
    {
        return new self(
            id: (int) $user['id'],
            name: (string) $user['name'],
            email: (string) $user['email'],
        );
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
