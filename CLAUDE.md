# CLAUDE.md

Guidage Claude Code pour le dépôt **top-sweepstakes.com**.

## Style de réponse
- Ne pas afficher de code dans les messages texte (les diffs sont visibles dans les tool calls).
- Réponses minimales : liste courte des fichiers modifiés et ce qui a changé.
- Pas de récapitulatif détaillé, pas de blocs de code répétés.

## Vue d'ensemble

Moteur de **jeux-concours US** (sweepstakes). Un participant arrive sur la landing d'un concours,
remplit un formulaire en deux étapes, puis se voit proposer des **offres partenaires en display**.
Chaque clic sur une offre sort vers la régie d'affiliation et constitue le revenu du site.

Il n'y a **ni sponsor, ni coregistration par opt-in, ni envoi de lead à un tiers** : les
participants restent en base locale. La monétisation est **entièrement du display click-out**.

Le principe directeur du dépôt : **un concours se crée en base, jamais en déployant du code.**
Un seul jeu de gabarits Twig sert tous les concours ; le back-office pilote le contenu et le thème.
Toute proposition qui aboutirait à un dossier de vues par concours est à refuser — c'est
précisément la dette de `meilleursconcours.com` que ce projet remplace.

## Stack

- **PHP 8.4** (image dérivée du registry Confluent), **Slim 4**, **Twig 3**, **PHP-DI 7**,
  **Doctrine DBAL 3**, **Phinx**, **MariaDB 11**, Docker.
- PSR-4 `App\` → `src/`. PSR-12 vérifié par PHPCS, analyse statique PHPStan niveau 5.
- Front public : HTML/CSS maison, pas de framework JS. Back-office : Bootstrap 5 + DataTables.

## Commandes

Tout s'exécute dans le conteneur `topsweepstakes_php`, projet monté sur
`/data/www/top-sweepstakes.com` (identique au chemin hôte).

```bash
./bin/setup.sh                       # première installation (build, up, composer, migrate)
./bin/update.sh                      # mise à jour d'un environnement existant

docker compose up -d --build
docker exec topsweepstakes_php composer install
docker exec topsweepstakes_php php vendor/bin/phinx status
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev
docker exec topsweepstakes_php php vendor/bin/phinx create NomDeLaMigration

docker exec topsweepstakes_php composer test    # PHPUnit
docker exec topsweepstakes_php composer stan    # PHPStan niveau 5
docker exec topsweepstakes_php composer cs      # PHPCS PSR-12

docker exec topsweepstakes_php php bin/cli.php --help   # tâches CLI

curl -s http://127.0.0.1:2032/health             # sonde : app + base
docker exec -it topsweepstakes_mariadb mariadb -u root -p bd_top_sweepstakes
```

Ports (loopback uniquement) : nginx **2032**, PHP-FPM **7032**, MariaDB **3332**.
SQLyog se connecte à MariaDB par tunnel SSH sur le port 3332.

## Architecture

- `public/index.php` → `src/app.php` (conteneur PHP-DI, Twig, middlewares) → `src/routes.php`.
- `src/bootstrap.php` charge le `.env` et ouvre la session. Fuseau **America/New_York**.
- `src/Core/` : `Config`, `Database`, `Logger`, `Csrf`, `Signer`.
- `src/Middleware/` : `SecurityHeadersMiddleware`, `CsrfMiddleware`.
- `src/Modules/<Domaine>/` : `Controllers/`, `Models/Repositories/`, `Services/`, `Tasks/`.
- `src/Views/front/` : gabarits publics, **un seul jeu pour tous les concours**.
- `src/Views/admin/` : back-office.
- `bin/cli.php` : task runner, registre explicite des commandes.

## Règles détaillées (`.claude/rules/`)

| Fichier | Sujet |
|---------|-------|
| `architecture.md` | bootstrap, conteneur DI, modules, routage, conventions PHP |
| `database.md` | conventions de schéma, repositories DBAL, pièges MariaDB |
| `migrations.md` | Phinx, nommage, réversibilité, procédure |
| `sweepstakes.md` | moteur de gabarit unique, champs, variantes, thème — **zéro vue par concours** |
| `offers-display.md` | **zone sensible** : sélection, affichage, clic, URL de sortie, caps |
| `tracking-stats.md` | événements, rollups, flux de revenus de la régie, eCPM |
| `legal-us.md` | Official Rules, TCPA, CAN-SPAM, CCPA, fragments legals |
| `frontend.md` | conventions Twig, thème, formulaires, back-office |
| `testing.md` | ce qu'on teste et comment on le prouve |
| `docker-commands.md` | conteneurs, chemins, logs, base |
| `deploy.md` | procédure de mise en production, checklist |

## Conventions

- Commits : conventional commits, message **en français** (`feat(offers): ...`, `fix(tracking): ...`).
  Le scope reprend le domaine touché (`sweepstakes`, `offers`, `tracking`, `stats`, `admin`, `legal`).
- Commentaires et logs en français. Identifiants de code et de base en anglais.
- **Ce qui n'est pas fait se déclare.** Une dette connue qui ne vit que dans une conversation est
  oubliée au moment où elle coûte cher. Elle se déclare dans `ReadinessCatalog` — ou, si le code
  peut la constater, devient un contrôle de `ReadinessService`. Les deux s'affichent dans
  `/admin/readiness` et dans le bandeau du back-office.
- **Aucun secret dans le code.** Identifiants de régie, clés d'API et mots de passe vivent dans
  `.env`. C'est la faute la plus fréquente de `meilleursconcours.com`
  (`app/Modules/Stats/Tasks/PlateformeTask.php:83` : login et mot de passe de la régie en clair).
- Toute donnée personnelle sortant du site est **encodée** et **listée explicitement** par offre.

## Zones sensibles

Une erreur y est invisible à l'écran et se voit sur la facturation ou devant un régulateur :

1. **La chaîne display** — sélection d'offre → impression → clic → URL de sortie.
   Voir `.claude/rules/offers-display.md`. Toute modification passe par l'agent
   `offer-tracking-verifier`.
2. **Le consentement** — `t_lead_consent` est immuable et archive le texte réellement affiché.
   Voir `.claude/rules/legal-us.md`. Toute modification d'un formulaire ou d'un texte de
   consentement passe par l'agent `compliance-us`.
