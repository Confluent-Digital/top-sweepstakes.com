---
description: Mise en production, checklist, zones à risque
---

# Déploiement

Branche principale : `main`. Une modification passe par une branche, une PR, une revue.

## Deux scripts, deux usages

| Script | Pour quoi | Ce qu'il fait |
|---|---|---|
| `./bin/setup.sh` | **première installation, en développement** | crée le `.env` s'il manque, monte les conteneurs (`compose up -d --build`), `composer install` **avec** les dépendances de développement, migre |
| `./bin/update.sh` | **mise à jour d'un environnement existant, production comprise** | `git pull --ff-only`, `composer install --no-dev --optimize-autoloader`, `phinx migrate -e prod`, purge du cache Twig |

`setup.sh` n'est **pas** le script de mise en production : il installe PHPUnit, PHPStan et PHPCS sur
le serveur, et reconstruit les images. Tester une mise en production, c'est lancer `update.sh` sur un
environnement déjà monté.

Le `composer install` de l'entrypoint suit la **même règle que
`App\Core\Config::isProduction()`** — égalité stricte avec `production`,
`development` par défaut. En production il pose `--no-dev --optimize-autoloader`
et retire donc PHPUnit, PHPStan et PHPCS ; ailleurs il installe tout.

**Phinx est dans `require`, pas `require-dev`** : jouer une migration est une
opération de production. L'y avoir laissé en dépendance de développement faisait
échouer `update.sh` à tous les coups — `composer install --no-dev` retirait
Phinx à la ligne précédant `vendor/bin/phinx migrate`. Ne pas l'y remettre.

Les deux sourcent `bin/lib.sh`, qui vérifie que le démon Docker répond et détecte Docker Compose —
plugin `docker compose` ou binaire `docker-compose`. Sans cette détection, un serveur où le plugin
manque répond `unknown shorthand flag: 'd' in -d`, un message du CLI Docker qui ne nomme ni Compose
ni sa cause et fait chercher le problème dans le script.

## Avant de committer

1. `php -l` sur les fichiers touchés — les hooks le font déjà, en direct et à l'arrêt.
2. `composer cs` et `composer stan` propres.
3. `composer test` vert.
4. Aucun secret dans le diff — le hook `no-secrets.sh` bloque, mais relire tout de même.
5. Selon la zone touchée :
   - chaîne display → agent `offer-tracking-verifier` ;
   - formulaire ou texte de consentement → agent `compliance-us` ;
   - reste → agent `php-code-reviewer`.

## Avant d'ouvrir le site au trafic

`/admin/readiness` — **Réserves d'ouverture** — liste ce qui n'est pas fait : les contrôles
automatiques (offre sans `idv`, concours publié sans règlement, page légale qui ne rend rien,
CGU anglaises qui n'en sont pas, absence de mention CCPA) et les points déclarés dans
`ReadinessCatalog` (règlement jamais relu par un juriste, lots du plan non développés).

Le verdict de cet écran fait foi : **aucun budget d'acquisition tant qu'il reste une réserve
bloquante non arbitrée**. Une réserve peut être fermée — « traité » ou « risque accepté » — mais
la décision est datée, signée et journalisée dans `t_admin_log`, et un contrôle automatique
encore au rouge reste affiché au rouge.

Un point ouvert que le code pourrait constater n'a rien à faire dans `ReadinessCatalog` :
il devient un contrôle dans `ReadinessService`.

## Après la mise en production

1. `curl -s https://top-sweepstakes.com/health` → `status: ok` **et** `database: ok`.
2. Parcours réel d'un concours actif, jusqu'à la page de remerciement.
3. Vérifier qu'une impression et un clic se sont bien inscrits dans `t_offer_event`.
4. Surveiller `logs/app.log` pendant les premières minutes.

## Zones à risque

| Changement | Ce qui casse si on se trompe |
|---|---|
| Format du `sid` | le rapprochement des revenus avec la régie, pour toujours |
| `OfferLinkBuilder` | les clics sortent mal attribués, ou fuitent des données personnelles |
| Comptage des impressions | les eCPM, donc l'ordre d'affichage, donc le revenu |
| Ordre des routes | `/{slug}` déclarée trop tôt avale `/admin`, `/out`, les pages légales |
| Texte de consentement | la valeur probante de tout ce qui est collecté ensuite |
| Migration sur une table peuplée | perte de données silencieuse |

## Retour arrière

`phinx rollback -e prod` puis `git revert`. Une migration qui ne sait pas revenir en arrière doit
le dire explicitement dans son `down()`, avec la procédure manuelle en commentaire.
