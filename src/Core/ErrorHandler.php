<?php

declare(strict_types=1);

namespace App\Core;

use Slim\Handlers\ErrorHandler as SlimErrorHandler;

/**
 * Gestionnaire d'erreurs qui dit CE QUI a ete demande.
 *
 * Le gestionnaire de Slim journalise l'exception et sa trace, jamais la requete.
 * Sur un 404 — l'erreur la plus frequente d'un site public — cela donne quinze
 * lignes de pile a travers les middlewares de Slim et pas un mot sur l'URL
 * demandee. Le journal est alors illisible : on sait qu'il y a eu un 404, on ne
 * peut pas savoir lequel, donc ni le corriger ni le classer sans importance.
 *
 * Une ligne de requete en tete change cela :
 *
 *     GET /favicon.ico — 404 Not Found ...
 *
 * L'IP n'y figure pas. Elle n'aiderait pas a corriger un 404, et
 * .claude/rules/legal-us.md tient a ce que les donnees personnelles hors base
 * restent l'exception, pas la regle.
 */
final class ErrorHandler extends SlimErrorHandler
{
    protected function logError(string $error): void
    {
        $uri = (string) $this->request->getUri()->getPath();
        $query = (string) $this->request->getUri()->getQuery();
        if ($query !== '') {
            $uri .= '?' . $query;
        }

        parent::logError(sprintf(
            '%s %s — %s',
            $this->method ?? $this->request->getMethod(),
            $uri,
            $error
        ));
    }
}
