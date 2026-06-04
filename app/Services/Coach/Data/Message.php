<?php

namespace App\Services\Coach\Data;

/**
 * A single, provider-agnostic conversation message.
 */
final readonly class Message
{
    public function __construct(
        public Role $role,
        public string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(Role::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(Role::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(Role::Assistant, $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
    }
}
