---
name: offer-new
description: Ajouter ou modifier une offre partenaire en display sur top-sweepstakes.com — identifiants de régie ids/idv, visuel, ciblage, caps, champs transmis, rattachement aux concours — et vérifier l'URL de sortie générée avant mise en ligne. À utiliser dès qu'il s'agit d'une offre, d'un annonceur ou d'un lien de sortie.
---

# Ajouter une offre display

Une offre est une source de revenus : mal paramétrée, elle affiche sans rapporter, ou rapporte
sans qu'on puisse le rapprocher. Voir `.claude/rules/offers-display.md`.

## Paramétrage (`t_offer`)

| Champ | Ce qu'il faut savoir |
|---|---|
| `offer_platform_ids` | identifiant du **site** côté régie. Généralement le même pour toutes les offres, il vient de `AFFILIATE_SITE_IDS`. |
| `offer_platform_idv` | identifiant de la **créa** côté régie. Il part dans l'URL de sortie et conditionne la diffusion : une offre sans `idv` n'est pas affichée. |
| `offer_platform_idc` | identifiant de **campagne** côté régie. **C'est la clé de rapprochement des revenus** : le flux de reporting rend ses lignes par `idc`, et une erreur ici attribue le chiffre d'affaires à une autre offre. À recopier depuis la régie, jamais à deviner. |
| `offer_platform_idc` | identifiant de campagne, utilisé par le flux de reporting. |
| `offer_type` | `banner` (visuel seul) ou `coupon` (texte + visuel + bouton). |
| `offer_passthrough_fields` | **liste blanche** des champs transmis dans l'URL de sortie. Par défaut : rien. On n'ajoute un champ que si l'annonceur l'a demandé et que le concours le collecte. |
| `offer_cap_day` / `offer_cap_total` | plafonds. Vérifiés **à l'affichage**, pas seulement à l'envoi. |
| `offer_date_start` / `offer_date_end` | fenêtre de diffusion. |
| `offer_target_blank` | ouverture dans un nouvel onglet. |

Le ciblage éventuel va dans `t_offer_targeting`, interprété par `TargetingService` — jamais en
condition écrite dans un gabarit.

## Visuel

Téléversé depuis le back-office vers `public/img/offers/`. Dimensions cohérentes avec les autres
offres du bloc : une image hors gabarit casse la grille et fait chuter le taux de clic des voisines.

## Vérification avant mise en ligne

Depuis le back-office, la prévisualisation affiche le rendu **et l'URL de sortie générée**.
Contrôler sur cette URL :

- la base vaut `AFFILIATE_TRACKING_BASE` ;
- `ids` et `idv` sont exactement ceux de la régie ;
- le `sid` suit `{sweepstake_id}_{subid}_{email_md5}_{date}` ;
- **tous les paramètres sont encodés** — tester avec un prénom contenant une esperluette, un signe
  égal, un espace et un accent ;
- **aucun champ hors `offer_passthrough_fields`** n'apparaît.

⛔ **Ne jamais ouvrir l'URL de sortie pour « vérifier que ça marche ».** Un clic réel est facturé à
l'annonceur et pollue le reporting. Les appels vers `cdflow*` sont refusés par
`.claude/settings.json`. La vérification se fait sur la chaîne d'URL, pas en la suivant.

Puis rattacher l'offre aux concours voulus et lancer l'agent `offer-tracking-verifier`.
