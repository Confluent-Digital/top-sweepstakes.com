---
description: Créer et appliquer une migration Phinx conforme aux conventions du dépôt
argument-hint: <VerbeObjet> (ex. AddCapColumnsToOffer)
---

Crée une migration Phinx nommée `$ARGUMENTS`, en respectant `.claude/rules/migrations.md` et
`.claude/rules/database.md`.

1. Génère le squelette :
   `docker exec topsweepstakes_php php vendor/bin/phinx create $ARGUMENTS`
2. Écris la migration :
   - tables `t_*`, colonnes préfixées, PK `<prefixe>_id`, FK indexées ;
   - `NOT NULL DEFAULT` sur tout booléen et toute énumération ;
   - `change()` si Phinx sait inverser, sinon `up()` / `down()` explicites ;
   - aucune logique métier, aucun appel de service ;
   - IP en `VARCHAR(45)` ; si la table contient des données personnelles, indique sa durée de
     conservation en commentaire et vérifie qu'elle est couverte par `gdpr:purge`.
3. Applique : `docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev`
4. Vérifie : `phinx status` propre, puis `SHOW CREATE TABLE` sur les tables touchées.
5. Rappelle si la migration touche la chaîne display ou le consentement — la vérification
   runtime correspondante est alors obligatoire.
