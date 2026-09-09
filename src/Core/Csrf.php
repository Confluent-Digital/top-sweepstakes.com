<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Jeton CSRF de session, partage par tous les formulaires du back-office.
 * Le front public n'en depend pas : ses formulaires sont ouverts par nature
 * (trafic paye, pas de session authentifiee a proteger).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::KEY];
    }

    public static function check(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }
}
