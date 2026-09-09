---
description: Conventions de schéma, repositories DBAL, pièges MariaDB
paths:
  - "src/Modules/**/Repositories/**"
  - "database/**"
---

# Base de données

MariaDB 11, `utf8mb4` / `utf8mb4_unicode_ci`. Accès par **Doctrine DBAL** (`App\Core\Database`),
pas d'ORM.

## Conventions de nommage

| Objet | Règle | Exemple |
|---|---|---|
| Table | `t_<nom_singulier>` | `t_sweepstake`, `t_offer_event` |
| Colonne | préfixée par le nom de table sans `t_` | `sweepstake_slug`, `offer_event_action` |
| Clé primaire | `<prefixe>_id`, `INT UNSIGNED AUTO_INCREMENT` | `offer_id` |
| Clé étrangère | `<prefixe>_id_<cible>` **et indexée** | `sweepstake_offer_id_offer` |
| Booléen | `TINYINT(1) NOT NULL DEFAULT 0` | `offer_active` |
| Horodatage | `created_at` / `updated_at` en `DATETIME` | |
| Énumération | `ENUM(...) NOT NULL DEFAULT '<valeur>'` | `offer_event_action` |

Le préfixe de colonne est verbeux, mais c'est la convention du parc : il rend les jointures
lisibles sans alias et évite les collisions de noms dans les `SELECT *` croisés.

## Requêtes

- **Toujours** des requêtes préparées avec paramètres nommés. Aucune concaténation d'une valeur
  venue d'une requête HTTP dans du SQL. `copyconfigAction` de `meilleursconcours.com` concatène
  des identifiants sans bind : injection possible depuis le back-office.
- Les listes d'identifiants passent par `Connection::executeQuery` avec
  `ArrayParameterType::INTEGER`, jamais par un `implode(',', ...)` maison.
- Une requête de plus de quinze lignes vit dans un repository, en constante ou en méthode nommée,
  jamais en ligne dans un contrôleur.

## Pièges

- **`NULL` n'est pas `0`** : `WHERE offer_active != 1` exclut les lignes où la colonne est `NULL`.
  D'où le `NOT NULL DEFAULT` systématique sur les booléens et les énumérations.
- **`ONLY_FULL_GROUP_BY`** est actif : toute colonne du `SELECT` absente des agrégats doit figurer
  dans le `GROUP BY`.
- **Les compteurs agrégés se font en `INSERT ... ON DUPLICATE KEY UPDATE`**, jamais en
  « lire puis écrire » : `ExamController::record()` de `meilleursconcours.com` fait un `findFirst`
  suivi d'un `save`, ce qui perd des événements en concurrence.
- **Une IP se stocke en `VARCHAR(45)`**, jamais en `INT` via `ip2long` : IPv6 existe, et une preuve
  de consentement amputée de l'IP ne vaut rien.

## Rétention

Toute table contenant des données personnelles porte une durée de conservation documentée dans
`.claude/rules/legal-us.md` et appliquée par `gdpr:purge`. Créer une telle table sans y penser,
c'est créer une dette de conformité.
