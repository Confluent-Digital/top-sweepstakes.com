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
home.html.twig            liste des concours ouverts
landing.html.twig         dotation, promesse, CTA, réassurance, disclaimer de marque
form.html.twig            étape 1 et étape 2, rendues depuis t_sweepstake_field
offer.html.twig           UNE offre, UNE page — voir offers-display.md
thankyou.html.twig        confirmation
rules.html.twig           Official Rules du concours
legal.html.twig           fragment servi par legals.confluent-digital.com
partials/field.html.twig  rendu d'un champ, tous types confondus
```

Il n'y a **qu'un seul gabarit d'offre**, quel que soit son format (`banner` ou `coupon`) : il
rend un unique élément cliquable. Deux gabarits par format ont existé et ont été supprimés — les
garder, c'était risquer qu'on en réintroduise un à côté du lien de la carte, et deux éléments
cliquables pour une même offre produisent deux clics pour une seule intention.

Les visuels vivent dans `public/img/sweepstakes/<id>/`, uploadés depuis le back-office.

## Longueur du tunnel : une ou deux étapes

Le nombre d'étapes du formulaire **n'est pas une option** : il se déduit de `sweepstake_field_step`.

- Des champs répartis sur les étapes 1 et 2 → tunnel en deux écrans.
- Tous les champs à l'étape 1 → **tunnel en un seul écran**, et `/details` renvoie sur `/entry`.

C'est volontairement une conséquence de la configuration et non une case à cocher : deux réglages
qui pourraient se contredire finissent toujours par le faire. Auparavant, un concours dont tous
les champs tenaient à l'étape 1 affichait quand même un second écran, vide, ne portant que les
consentements — un abandon offert.

**Les consentements se présentent toujours à la dernière étape peuplée**, quelle qu'elle soit :
le participant doit savoir ce qu'il donne avant de consentir.

Un tunnel court convertit mieux mais qualifie moins. L'arbitrage se mesure — taux de complétion
par étape dans `t_lead` — il ne se devine pas.

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
