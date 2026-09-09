---
description: Bootstrap, conteneur DI, modules, routage, conventions PHP
paths:
  - "src/app.php"
  - "src/routes.php"
  - "src/bootstrap.php"
  - "src/Core/**"
  - "src/Middleware/**"
---

# Architecture

## Amorçage — un seul chemin

```
public/index.php  →  src/app.php  →  src/bootstrap.php (.env, session, fuseau)
                                  →  conteneur PHP-DI
                                  →  Twig + middlewares
                                  →  src/routes.php
```

`bin/cli.php` charge **le même `src/app.php`** et récupère le conteneur. Il n'y a donc
**pas de DI dupliqué** entre le web et la CLI — c'est l'écueil de `meilleursconcours.com`,
où `config/services.php` et `cli.php` déclarent les mêmes services en double et divergent
silencieusement (un service corrigé d'un seul côté fait que le site et les crons ne voient
pas la même chose). Ne jamais réintroduire une seconde définition de conteneur.

## Conteneur

Enregistrement explicite dans `src/app.php`, par closure, dans l'ordre :
noyau → repositories → services → tâches → contrôleurs implicites (autowiring PHP-DI).

Un service se déclare une fois. S'il a besoin de la base, il prend `App\Core\Database` en
constructeur, jamais une connexion globale.

## Modules

`src/Modules/<Domaine>/` avec au plus quatre sous-dossiers :

```
Controllers/            entrée HTTP, aucune logique métier
Models/Repositories/    accès base (DBAL), une classe par agrégat
Services/               logique métier, testable sans HTTP ni base quand c'est possible
Tasks/                  points d'entrée CLI, enregistrés dans le registre de bin/cli.php
```

Un contrôleur ne construit pas de SQL. Un service ne lit pas `$_GET`. Une tâche ne rend pas de HTML.

## Routage

Tout dans `src/routes.php`, groupé par domaine, du plus spécifique au plus générique.

⚠️ **La route catch-all du concours (`/{slug}`) doit être déclarée en dernier**, après
`/health`, `/out/...`, `/admin/...` et les pages légales — sinon elle les avale.

## Conventions PHP

- `declare(strict_types=1);` en tête de chaque fichier.
- `final class` par défaut ; l'héritage se justifie, il ne se subit pas.
- Propriétés promues en constructeur, typées.
- PSR-12 (`composer cs`), PHPStan niveau 5 (`composer stan`).
- Pas de `echo` ni de `var_dump` hors CLI. `CiblageService` de `meilleursconcours.com` fait des
  `echo` de debug en plein rendu web : c'est l'exemple à ne pas suivre.
- Les exceptions métier remontent ; les erreurs d'infrastructure (régie injoignable, service de
  vérification muet) **dégradent sans casser la page** — un participant ne doit jamais voir une
  erreur parce qu'un tiers ne répond pas.
