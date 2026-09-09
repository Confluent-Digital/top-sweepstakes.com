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

## Images

Les visuels ne sont **jamais stockés tels quels** : `ImageUploadService` les décode puis les
réencode, donc les reconstruit. Un polyglotte — image valide portant du PHP dans ses métadonnées —
n'y survit pas, là où une simple vérification du type MIME le laisserait passer.

Chaîne appliquée : redimensionnement à 1 200 px, réencodage en qualité 82, quantification 8 bits
des PNG par `pngquant`, et WebP écrit à côté du repli.

**Le WebP n'est conservé que s'il est plus léger que le repli.** Mesuré : sur une photo il pèse la
moitié du JPEG, mais sur un aplat transparent un PNG quantifié le bat largement — 4,9 Ko contre
11,1 Ko sur un logo détouré. Le gabarit servant le WebP dès qu'il existe, le garder ferait payer au
visiteur le double du nécessaire.

Les **dimensions réelles** sont enregistrées en base (`*_image_width` / `*_image_height`) : sans
elles, le gabarit réserve une place fixe et le bouton descend au chargement d'un visuel portrait,
au moment où le visiteur vise.

## Rétention

Toute table contenant des données personnelles porte une durée de conservation documentée dans
`.claude/rules/legal-us.md` et appliquée par `gdpr:purge`. Créer une telle table sans y penser,
c'est créer une dette de conformité.
