<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Empeche un jeu de donnees de demonstration d'atterrir en production.
 *
 * Les seeds de ce depot ne posent pas des donnees neutres : ils publient des
 * concours — `sweepstake_status = 'published'` — avec des reglements generes
 * jamais relus par un juriste, une adresse AMOE en France, et des offres
 * portant des identifiants de regie factices (`DEMO1000`, `ids=996`). Les
 * lancer sur un domaine public mettrait en ligne de faux jeux-concours
 * americains, immediatement visibles et immediatement opposables.
 *
 * Rien dans `phinx seed:run` ne distingue un environnement d'un autre : la
 * commande est la meme, et un seul `-e prod` de trop suffit. Ce garde-fou
 * remplace cette absence de distinction par un refus explicite.
 *
 * La derogation existe — un premier deploiement peut vouloir les reglages du
 * site — mais elle doit etre ecrite, pas deduite.
 */
final class SeedGuard
{
    public const OVERRIDE = 'ALLOW_SEEDS_IN_PRODUCTION';

    /**
     * @param array<string,mixed>|null $env pour les tests ; $_ENV par defaut
     */
    public static function refuseInProduction(string $seeder, ?array $env = null): void
    {
        $env ??= $_ENV;
        $config = new Config($env);

        if (!$config->isProduction()) {
            return;
        }
        if ($config->bool(self::OVERRIDE)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            "%s refuse de s'executer : APP_ENV=production.\n\n"
            . "Les seeds publient des concours de demonstration, avec des identifiants de regie\n"
            . "factices et des reglements jamais relus. En production, il faut a la place :\n\n"
            . "  php vendor/bin/phinx migrate -e prod     # le schema, lui, est obligatoire\n"
            . "  php bin/cli.php admin:create --email=... # le compte de back-office\n"
            . "  puis creer les concours depuis /admin/sweepstakes\n\n"
            . "Si vous savez ce que vous faites : %s=1 dans le .env.",
            $seeder,
            self::OVERRIDE
        ));
    }
}
