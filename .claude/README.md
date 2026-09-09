# `.claude/` — configuration projet

Guides, règles, agents et skills propres à **top-sweepstakes.com**
(PHP 8.4 / Slim 4 / Twig 3 / Doctrine DBAL / MariaDB 11).

L'architecture générale vit dans `CLAUDE.md` à la racine ; les invariants détaillés sont ici,
segmentés par sujet.

## Layout

- `settings.json` — pré-autorise les commandes courantes (docker exec sur les conteneurs nommés,
  composer, phinx, git, curl sur le port local) et **bloque** les destructives
  (`rm -rf src|public|vendor|database`, `docker compose down -v`, `DROP DATABASE`, `TRUNCATE`,
  `git push --force`, `git reset --hard`) ainsi que **tout appel sortant vers la régie**
  (`cdflow*`, `plateforme.confluent-digital.com`) — un clic ou une conversion de test est facturé.
- `rules/` — les invariants, par sujet.
- `agents/` — sous-agents spécialisés, invoqués via `Agent(subagent_type: "<nom>")`.
- `skills/` — skills déclenchés automatiquement quand la description correspond à la demande.
- `commands/` — slash commands projet.
- `hooks/` — scripts déclenchés par le harness, enregistrés dans `settings.json`.

## Règles

| Fichier | Sujet |
|---|---|
| `architecture.md` | bootstrap unique web/CLI, conteneur DI, modules, routage, conventions PHP |
| `database.md` | conventions de schéma, requêtes préparées, pièges MariaDB, rétention |
| `migrations.md` | Phinx : nommage, réversibilité, procédure, seeds |
| `sweepstakes.md` | **moteur de gabarit unique** — un concours se crée en base, jamais en code |
| `offers-display.md` | **zone sensible** — sélection, impression, clic, URL de sortie, caps |
| `tracking-stats.md` | événementiel vs agrégat, flux de revenus de la régie, eCPM, sources |
| `legal-us.md` | **zone sensible** — Official Rules, TCPA, CAN-SPAM, CCPA, preuve de consentement, rétention |
| `frontend.md` | Twig, thème, formulaires publics, back-office |
| `testing.md` | ce qui se teste unitairement, ce qui se prouve à l'exécution, les interdits |
| `docker-commands.md` | conteneurs, chemins, image PHP dérivée, logs, accès SQLyog |
| `deploy.md` | procédure, checklist, zones à risque, retour arrière |

## Agents

| Agent | Rôle |
|---|---|
| `php-code-reviewer` | Revue statique avant commit. Injection SQL, secrets, échappement Twig, conventions de schéma, ordre des routes, dette de `meilleursconcours` réintroduite. Verdict VALIDE / REJETÉ. |
| `offer-tracking-verifier` | **Vérification runtime de la chaîne display.** Prouve qu'une offre rendue produit une impression et une seule, qu'un `/out/` produit un clic et une redirection correcte, et que l'URL de sortie est bien formée et encodée. Ne tire jamais de clic réel. |
| `compliance-us` | **Conformité réglementaire US.** Preuve de consentement, TCPA, Official Rules, États exclus réellement appliqués, CAN-SPAM, CCPA, fuite de données personnelles, rétention. Verdict CONFORME / NON CONFORME. |
| `security-auditor` | Audit sécurité en lecture seule, avec scénario d'exploitation. Redirection ouverte sur `/out/`, IDOR, XSS stocké, back-office non protégé. |
| `debugger` | Réparation chirurgicale : reproduire, isoler à `fichier:ligne`, corriger au minimum, prouver. Connaît les pièges du dépôt. |
| `landing-cro` | Revue de conversion du tunnel public. Friction, champs superflus, mobile, performance. Pas d'avis esthétique. |

## Skills

| Skill | Déclenché sur |
|---|---|
| `sweepstake-new` | créer, dupliquer ou publier un concours — **entièrement en base** |
| `offer-new` | ajouter ou modifier une offre display, vérifier son URL de sortie |

## Slash commands

| Commande | Effet |
|---|---|
| `/migrate <VerbeObjet>` | migration Phinx conforme aux conventions, appliquée et vérifiée |
| `/predeploy` | checklist complète avant commit ou mise en production, verdict PRÊT / BLOQUÉ |
| `/new-sweepstake <nom>` | applique le skill `sweepstake-new` de bout en bout |

## Hooks

| Hook | Événement | Rôle |
|---|---|---|
| `php-lint.sh` | PostToolUse(Edit/Write) | `php -l` sur le fichier édité — **bloquant** |
| `lib-php-lint.sh` | (bibliothèque) | lint avec le PHP **du conteneur** (8.4), pas celui de l'hôte (8.3) — voir `rules/docker-commands.md` |
| `twig-cache-clear.sh` | PostToolUse(Edit/Write) | vide `cache/twig/*` dès qu'un `.twig` est édité |
| `no-secrets.sh` | PostToolUse(Edit/Write) | refuse un identifiant ou une clé écrits en dur — **bloquant** |
| `php-lint-stop.sh` | Stop | `php -l` sur tous les PHP modifiés sur la branche — **bloquant**, avec garde anti-boucle |

## D'où vient ce dépôt

Le métier vient de `meilleursconcours.com` (PHP 7.4 / Phalcon 4), la structure technique de
`friday.confluent-digital.com` (Slim 4 / DBAL / Phinx). Les règles citent régulièrement la dette
du premier, non par archéologie, mais parce que chaque invariant d'ici existe pour ne pas la
reproduire : un dossier de vues par concours, la logique de ciblage dupliquée six fois, les
identifiants de régie en clair, les données personnelles non encodées dans les URL de sortie,
et l'absence de preuve de consentement.
