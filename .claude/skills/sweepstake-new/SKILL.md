---
name: sweepstake-new
description: Créer un nouveau jeu-concours sur top-sweepstakes.com, entièrement en base, sans écrire de gabarit ni déployer de code. Couvre la fiche concours, les champs du formulaire, le thème, les visuels, les Official Rules, les variantes A/B, le rattachement des offres display et la vérification avant publication. À utiliser dès qu'il s'agit d'ajouter, dupliquer ou publier un concours.
---

# Créer un concours

**Règle absolue : aucun fichier de gabarit n'est créé.** Si la demande semble exiger une mise en
page propre à ce concours, la réponse est un champ de configuration en base, pas un fichier Twig.
C'est l'invariant du dépôt (`.claude/rules/sweepstakes.md`).

## Le chemin le plus court : dupliquer

Un concours proche existe presque toujours. Le back-office duplique la fiche, ses champs, son
thème et le rattachement de ses offres. Il ne reste qu'à changer la dotation, les dates, les
visuels et les Official Rules.

## Sinon, dans l'ordre

1. **Fiche concours** (`t_sweepstake`)
   - `slug` — court, en anglais, sans année si le concours peut être reconduit (`amazon-750`).
     Il est dans l'URL et dans le `sid` envoyé à la régie : le changer après publication casse
     l'attribution des revenus déjà collectés.
   - dotation : titre, valeur en dollars, visuel ;
   - `date_start` / `date_end`, `min_age`, `excluded_states` ;
   - `sponsor_disclaimer` — obligatoire dès que la dotation porte une marque tierce ;
   - méta SEO, texte de remerciement ;
   - statut : **`draft`** tant que les Official Rules ne sont pas complètes.

2. **Champs du formulaire** (`t_sweepstake_field`)
   Chaque champ retenu doit avoir un usage. Un champ collecté et jamais exploité coûte des
   participants à la saisie et crée une obligation de conservation.
   Répartition habituelle : identité et e-mail à l'étape 1, adresse et téléphone à l'étape 2.

3. **Thème** (`t_sweepstake.theme`) — couleurs, police, visuel de fond. Rien d'autre.

4. **Visuels** — téléversés depuis le back-office vers `public/img/sweepstakes/<id>/`.
   Format `webp` avec repli, dimensions déclarées dans le gabarit.

5. **Official Rules** — voir `.claude/rules/legal-us.md` pour la liste des mentions obligatoires.
   Les États exclus des règles doivent correspondre exactement à `excluded_states`.

6. **Offres display** — rattachement dans `t_sweepstake_offer`, et **nombre d'étapes** dans
   `sweepstake_offer_steps` : les offres sont présentées une par page, dans l'ordre de l'eCPM.
   Vérifier que chaque offre rattachée est active, dans ses dates, pourvue de ses identifiants de
   régie (`idv` pour être diffusée, `idc` pour que son revenu lui soit rattaché) et pertinente
   pour du trafic US.

   Quatre étapes est un point de départ raisonnable. Plus d'offres augmente le revenu par
   participation jusqu'au point où la fatigue fait abandonner ; ce point se mesure dans les
   statistiques, il ne se devine pas.

7. **Variantes A/B** (facultatif) — `t_sweepstake_variant`, avec `device` explicite et poids.

## Avant de publier

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:2032/<slug>
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:2032/<slug>/rules
```

- Parcours complet jusqu'à la page de remerciement, sur mobile et sur ordinateur.
- Une ligne `t_lead` et les lignes `t_lead_consent` attendues sont écrites.
- Les impressions d'offres apparaissent dans `t_offer_event`, une par étape réellement atteinte —
  et une seule, même après rechargement de la page.
- Un participant d'un État exclu est **refusé**, avec un message explicite.
- Agent `compliance-us` sur les Official Rules et les textes de consentement.
- Agent `landing-cro` sur le tunnel.

Passer le statut à `published` en dernier.
