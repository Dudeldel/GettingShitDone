<?php

namespace App\Dto\Payload;

/**
 * Command: create the first (and only) account. Built by the controller from
 * RegisterRequest::validated() and passed into AuthService.
 */
class RegisterPayload
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            email: (string) $data['email'],
            password: (string) $data['password'],
        );
    }

    /**
     * @return array{name: string, email: string, password: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
