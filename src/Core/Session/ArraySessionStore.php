<?php

declare(strict_types=1);

namespace App\Core\Session;

/** Session en memoire, pour les tests. */
final class ArraySessionStore implements SessionStore
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /** Sans effet : il n'y a pas d'identifiant a renouveler hors d'une vraie session. */
    public function regenerate(): void
    {
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
