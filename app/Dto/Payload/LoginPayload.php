<?php

namespace App\Dto\Payload;

/**
 * Command: authenticate with email + password. Built by the controller from
 * LoginRequest::validated() and passed into AuthService.
 */
class LoginPayload
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: (string) $data['email'],
            password: (string) $data['password'],
        );
    }

    /**
     * @return array{email: string, password: string}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
