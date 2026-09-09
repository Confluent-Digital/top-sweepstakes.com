<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Signature HMAC courte des identifiants exposes dans les URLs publiques
 * (l'uid d'une offre dans /out/{uid}). Empeche d'enumerer ou de forger un
 * clic sur une offre qui n'a pas ete servie a ce visiteur.
 */
final class Signer
{
    public function __construct(private Config $config)
    {
    }

    public function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->config->require('APP_SECRET')), 0, 16);
    }

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }
}
