---
description: Phinx — création, nommage, réversibilité, procédure
paths:
  - "database/migrations/**"
  - "database/seeds/**"
  - "phinx.php"
---

# Migrations (Phinx)

```bash
docker exec topsweepstakes_php php vendor/bin/phinx create CreateOfferTables
docker exec topsweepstakes_php php vendor/bin/phinx status
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev
docker exec topsweepstakes_php php vendor/bin/phinx rollback -e dev
```

## Règles

- Un fichier par changement cohérent. Nom en `CamelCase` décrivant l'intention
  (`AddCapColumnsToOffer`), pas le mécanisme (`Migration12`).
- **`change()` quand Phinx sait inverser** (création de table, ajout de colonne ou d'index).
  `up()` / `down()` explicites dès qu'il y a du SQL brut ou une transformation de données.
- **Aucune logique métier dans une migration.** Une migration crée ou modifie du schéma, et
  au besoin transforme des données existantes. Elle n'appelle ni service, ni tâche, ni API.
- Toute clé étrangère est indexée. Toute colonne servant à filtrer une DataTable est indexée.
- Un `ENUM` reçoit `NOT NULL DEFAULT`, sans exception.
- Ajouter une colonne à une table peuplée : `NULL` autorisé ou `DEFAULT` fourni, jamais
  `NOT NULL` sans valeur par défaut sur une table non vide.

## Après avoir migré

1. `phinx status` doit être propre — aucune migration `down` en attente.
2. Vérifier le schéma réel (`SHOW CREATE TABLE`) et non seulement le retour de la commande.
3. Si la migration touche une table de la chaîne display ou de consentement, relancer la
   vérification décrite dans `offers-display.md` / `legal-us.md`.

## Seeds

`database/seeds/` contient de quoi faire tourner le site en développement : un concours de
démonstration, ses champs, quelques offres. **Aucune donnée personnelle réelle**, aucun
identifiant de régie valide.
