---
description: Créer un nouveau jeu-concours de bout en bout, entièrement en base
argument-hint: <nom du concours et dotation> (ex. "Walmart 500 $ gift card")
---

Applique le skill `sweepstake-new` pour créer le concours : $ARGUMENTS

Rappel de l'invariant : **aucun gabarit Twig n'est créé, aucun déploiement n'est nécessaire.**
Tout se règle par la fiche concours, ses champs, son thème, ses visuels, ses Official Rules et le
rattachement de ses offres.

Termine par la vérification avant publication décrite dans le skill, et laisse le concours en
statut `draft` tant que les Official Rules ne sont pas complètes.
