---
description: Moteur de gabarit unique — champs, variantes, thème, création d'un concours
paths:
  - "src/Modules/Sweepstakes/**"
  - "src/Views/front/**"
---

# Moteur de concours

## L'invariant du dépôt

**Un concours se crée en base. Jamais en déployant du code.**

`meilleursconcours.com` a 64 dossiers `app/Modules/Concours/Views/<id>/` et autant de
`public/css/<id>/`, parce que le contrôleur choisit sa vue avec
`$this->view->pick($concours_number . '/etape/etape-' . $etape_number)`. Créer un concours y
demande de copier sept fichiers Volt, d'y remplacer un identifiant partout, de créer un dossier
CSS, puis de déployer. Les vues ne diffèrent que par un titre et une regex de code postal.

Ici, le chemin de gabarit ne dépend **jamais** d'un identifiant de concours. Si une demande
semble exiger un gabarit propre à un concours, la réponse est un champ de configuration
supplémentaire, pas un fichier.

## Ce qui est paramétrable en base

| Table | Pilote |
|---|---|
| `t_sweepstake` | slug, nom, statut, dotation (titre, valeur, visuel), dates, âge minimum, États exclus, disclaimer de marque, méta SEO, Official Rules, texte de remerciement |
| `t_sweepstake_field` | quels champs, à quelle étape, dans quel ordre, obligatoires ou non |
| `t_sweepstake_variant` | variantes A/B : device ciblé, poids, surcharges de contenu |
| `t_sweepstake.theme` | couleurs, police, visuel de fond — injectés en variables CSS |

## Gabarits

Un seul jeu, dans `src/Views/front/` :

```
layout.html.twig          en-tête, pied de page, liens légaux, variables CSS du thème
landing.html.twig         dotation, promesse, CTA, badges, disclaimer de marque
form.html.twig            étape 1 et étape 2, rendues depuis t_sweepstake_field
offers.html.twig          blocs d'offres display
thankyou.html.twig        confirmation
partials/field_*.html.twig    un partial par type de champ
partials/offer_*.html.twig    un partial par type d'offre (banner, coupon)
```

Les visuels vivent dans `public/img/sweepstakes/<id>/`, uploadés depuis le back-office.

## Variantes A/B

La variante est tirée à la première visite et **fixée en session** pour toute la durée du
parcours. Un participant qui change de variante entre l'étape 1 et l'étape 2 rend le test
ininterprétable et fausse l'attribution des revenus.

Le `device` est une colonne explicite (`desktop|mobile|all`). Ne pas reproduire l'`id_template`
magique de `meilleursconcours.com`, où `1 = desktop` et `2 = mobile` sont câblés en dur dans un
repository, sans que rien dans le schéma ne le dise.

## Validation des champs

Les règles de validation US vivent dans un service, pas dans les gabarits :
code postal à 5 chiffres, États sur deux lettres, téléphone au format NANP,
âge calculé depuis la date de naissance et comparé à `sweepstake_min_age`.

Un participant résidant dans un État exclu est refusé **avant** l'enregistrement, avec un
message explicite — pas silencieusement.
