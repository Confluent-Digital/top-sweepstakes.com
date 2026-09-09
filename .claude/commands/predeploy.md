---
description: Contrôles obligatoires avant commit ou mise en production
---

Exécute la checklist de `.claude/rules/deploy.md` sur le travail en cours et rends un verdict.

1. `docker exec topsweepstakes_php composer cs`
2. `docker exec topsweepstakes_php composer stan`
3. `docker exec topsweepstakes_php composer test`
4. `curl -s http://127.0.0.1:2032/health` → `status: ok` **et** `database: ok`
5. `git diff --staged` relu : aucun secret, aucune donnée personnelle en dur, aucune URL de régie
   écrite en dehors de `OfferLinkBuilder`.
6. Selon la zone touchée par le diff :
   - `src/Modules/Offers`, `src/Modules/Tracking`, gabarits d'offres → agent `offer-tracking-verifier` ;
   - formulaire public, texte de consentement, Official Rules, pages légales → agent `compliance-us` ;
   - migration → `phinx status` propre et schéma réel vérifié ;
   - reste → agent `php-code-reviewer`.

Verdict final : PRÊT ou BLOQUÉ, avec la liste de ce qui reste à faire.
