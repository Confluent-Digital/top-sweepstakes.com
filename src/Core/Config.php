<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @param array<string,mixed> $env */
    public function __construct(private array $env)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->env[$key] ?? null;
        if ($value === null) {
            $fromEnv = getenv($key);
            $value = $fromEnv === false ? null : $fromEnv;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    /** Comme get(), mais leve si la valeur manque : pour les secrets sans repli acceptable. */
    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException(sprintf('Configuration manquante : %s', $key));
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return $default;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $raw = $this->get($key);
        return $raw === null ? $default : (int) $raw;
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'development') === 'production';
    }
}
