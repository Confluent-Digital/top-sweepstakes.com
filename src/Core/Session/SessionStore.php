<?php

declare(strict_types=1);

namespace App\Core\Session;

/**
 * Acces a la session, derriere une interface pour que le contexte visiteur
 * reste testable sans demarrer de session PHP.
 */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;
}
