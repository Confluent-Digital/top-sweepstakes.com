---
description: ZONE SENSIBLE — sélection, affichage, clic, URL de sortie, caps
paths:
  - "src/Modules/Offers/**"
  - "src/Modules/Tracking/**"
  - "src/Views/front/offers.html.twig"
  - "src/Views/front/partials/offer_*"
---

# Offres display — zone sensible

C'est la seule source de revenus du site. Une erreur ici ne se voit pas à l'écran :
elle se voit sur la facturation, un mois plus tard.

## La chaîne

```
OfferSelector          quelles offres, pour ce participant, sur cette page
   ↓
rendu du bloc          une impression enregistrée par offre affichée — une seule
   ↓
/out/{offer_uid}       clic enregistré côté serveur, puis 302
   ↓
OfferLinkBuilder       URL de la régie : ids, idv, sid, champs autorisés, tout encodé
```

## Règles intangibles

1. **Une offre affichée = exactement une impression.** Dans `meilleursconcours.com`, un display
   coupon porte le même `data-idv` sur trois liens (image, bouton, wrapper) : le compteur
   d'impressions est gonflé de trois pour ce type d'offre et de un pour les autres. Les eCPM
   calculés là-dessus sont faux, et ce sont eux qui pilotent l'arbitrage.

2. **Le clic est enregistré côté serveur, dans `/out/`, avant la redirection.** Pas d'AJAX de
   tracking sur un `<a href>` pointant directement vers la régie : un bloqueur, un onglet fermé
   trop vite ou un JS en erreur perdent le clic — et le clic, c'est le revenu.

3. **L'URL de sortie ne se construit que dans `OfferLinkBuilder`.** Jamais dans un gabarit, jamais
   par concaténation dans un contrôleur. La base de la régie vient de `AFFILIATE_TRACKING_BASE`
   dans le `.env`.

4. **Tout paramètre est encodé (`urlencode`), et les données personnelles transmises sont limitées
   à `offer_passthrough_fields`.** Aujourd'hui `meilleursconcours.com` envoie civilité, nom,
   prénom, email, date de naissance, adresse, code postal, ville et téléphone en clair et sans
   encodage, à toutes les offres, sans distinction — un prénom contenant `&` casse l'URL, et un
   annonceur reçoit des données qu'il n'a pas demandées.

5. **Les caps sont vérifiés à l'affichage, pas seulement à l'envoi.** Dans l'existant, les quotas
   ne s'appliquent qu'au cron d'envoi des coregs : une offre plafonnée continue de s'afficher et
   consomme des impressions qui ne seront jamais payées.

6. **`OfferSelector` reste lisible.** Filtres, tri par eCPM, exploration ε-greedy explicite, limite
   par bloc. Si la méthode dépasse deux cents lignes, elle est à découper — pas à étendre.
   La référence négative est `getPartenairesToShow()` : cinq cent cinquante lignes dont deux cent
   cinquante commentées.

7. **Le ciblage vit dans `TargetingService`, en un seul endroit**, et rend un booléen.
   `EtapeController` duplique la même boucle de ciblage six fois, une par type de partenaire.
   Ici il n'y a qu'un type d'offre et qu'une implémentation.

## Après toute modification de cette zone

Lancer l'agent `offer-tracking-verifier`. Il prouve à l'exécution qu'une offre rendue produit une
impression et une seule, qu'un appel à `/out/` produit un clic et une seule redirection, et que
l'URL cible porte les bons `ids`/`idv`/`sid` avec des paramètres encodés et limités à la liste
blanche.

**Il ne tire jamais de vrai clic vers la régie** : un clic réel est facturé et fausse le reporting.
Les appels sortants vers `cdflow*` et `plateforme.confluent-digital.com` sont refusés par
`.claude/settings.json`.
