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

    /**
     * Renouvelle l'identifiant de session en conservant son contenu.
     *
     * A appeler a l'ouverture d'une session authentifiee : un identifiant pose
     * par un tiers AVANT la connexion — par un lien, un sous-domaine, un proxy
     * — ne doit pas survivre a celle-ci, sans quoi ce tiers se retrouve
     * connecte en meme temps que l'utilisateur.
     */
    public function regenerate(): void;
}
