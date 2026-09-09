---
description: ZONE SENSIBLE — sélection, affichage, clic, URL de sortie, caps
paths:
  - "src/Modules/Offers/**"
  - "src/Modules/Tracking/**"
  - "src/Views/front/offer.html.twig"
---

# Offres display — zone sensible

C'est la seule source de revenus du site. Une erreur ici ne se voit pas à l'écran :
elle se voit sur la facturation, un mois plus tard.

## Le modèle : une offre, une page

Les offres ne sont **pas** présentées toutes ensemble. Le participant les voit une par une,
chacune sur sa page, avec une progression affichée — `/{slug}/offers/{step}`.

```
/{slug}/offers          OfferSelector choisit la séquence, UNE FOIS, puis elle est figée en session
   ↓
/{slug}/offers/1..N     une offre par page ; l'impression est écrite À L'AFFICHAGE de la page
   ↓
/out/{jeton}            clic enregistré côté serveur, puis 302
   ↓
OfferLinkBuilder        URL de la régie : ids, idv, sid, champs autorisés, tout encodé
```

Le nombre d'étapes vient de `t_sweepstake.sweepstake_offer_steps`, réglable par concours dans le
back-office. `0` désactive le parcours.

**Deux conséquences que ce modèle rend possibles, et qu'il ne faut pas perdre :**

1. **Une offre jamais atteinte ne compte aucune impression.** Un participant qui abandonne à la
   deuxième étape ne fait rien compter aux offres 3 et 4. Dans le modèle « toutes les offres sur
   une page », elles auraient toutes compté une impression, et l'eCPM des dernières se serait
   effondré sans qu'elles aient jamais été vues — l'arbitrage les aurait alors reléguées à tort.

2. **Un rechargement ne compte pas deux fois.** L'étape vue est mémorisée en session
   (`VisitorContext::hasSeenOfferStep`). La régie ne paie qu'une exposition ; notre compte dit
   pareil.

**La séquence est figée en session**, comme la variante A/B. La retirer à chaque page ferait
revoir la même offre au participant et priverait l'ordre par eCPM de tout sens.

## Règles intangibles

1. **Une offre affichée = exactement une impression, écrite côté serveur.** Dans
   `meilleursconcours.com`, un display coupon porte le même `data-idv` sur trois liens (image,
   bouton, wrapper) et l'impression part en JavaScript, par élément : le compteur est gonflé de
   trois pour ce type d'offre et de un pour les autres. Les eCPM calculés là-dessus sont faux, et
   ce sont eux qui pilotent l'arbitrage.

   Corollaire de mise en page : **un seul élément cliquable par offre**. Deux boutons pointant la
   même offre produiraient deux clics pour une seule intention.

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

   **Limite connue et assumée** : le plafond est évalué au moment où la séquence est constituée,
   pas à chaque page. Un visiteur dont le parcours a démarré avant que le plafond ne soit atteint
   continuera de voir l'offre jusqu'au bout. Le dépassement est donc borné par le nombre de
   parcours en vol au moment du basculement — quelques unités, pas un ordre de grandeur.

   C'est la contrepartie du figeage de la séquence, qui est lui-même nécessaire (sans lui, le
   visiteur reverrait la même offre d'une page à l'autre). Vérifier le plafond à chaque étape
   ferait disparaître une offre en plein parcours et donnerait une progression qui recule.
   À connaître si un annonceur conteste un léger dépassement de quota.

6. **Refuser doit être aussi accessible qu'accepter.** Le bouton « No thanks » est au même
   endroit, visible sans défiler, et la page dit que la participation est déjà enregistrée. Un
   participant qui croit devoir cliquer pour valider son inscription clique par contrainte :
   l'annonceur reçoit du trafic sans intention, et l'offre finit par être coupée.

7. **`OfferSelector` reste lisible.** Filtres, tri par eCPM, exploration ε-greedy explicite, limite
   par bloc. Si la méthode dépasse deux cents lignes, elle est à découper — pas à étendre.
   La référence négative est `getPartenairesToShow()` : cinq cent cinquante lignes dont deux cent
   cinquante commentées.

8. **Le ciblage vit dans `TargetingService`, en un seul endroit**, et rend un booléen.
   `EtapeController` duplique la même boucle de ciblage six fois, une par type de partenaire.
   Ici il n'y a qu'un type d'offre et qu'une implémentation.

## Après toute modification de cette zone

Lancer l'agent `offer-tracking-verifier`. Il prouve à l'exécution qu'une offre rendue produit une
impression et une seule, qu'un rechargement n'en produit pas une seconde, qu'une étape non
atteinte n'en produit aucune, qu'un appel à `/out/` produit un clic et une seule redirection, et
que l'URL cible porte les bons `ids`/`idv`/`sid` avec des paramètres encodés et limités à la liste
blanche.

**Il ne tire jamais de vrai clic vers la régie** : un clic réel est facturé et fausse le reporting.
Les appels sortants vers `cdflow*` et `plateforme.confluent-digital.com` sont refusés par
`.claude/settings.json`.
