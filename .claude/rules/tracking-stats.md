---
description: Événements, rollups, flux de revenus de la régie, eCPM
paths:
  - "src/Modules/Tracking/**"
  - "src/Modules/Platform/**"
  - "src/Modules/Stats/**"
---

# Tracking et statistiques

## Deux niveaux, deux usages

| Table | Grain | Sert à |
|---|---|---|
| `t_offer_event` | un événement, avec `lead_id` | comprendre un parcours, déboguer, recouper une contestation |
| `t_offer_stats_daily` | agrégat au jour | afficher les écrans de stats sans scanner des millions de lignes |

`meilleursconcours.com` n'a que l'agrégat (`t_concours_stats_site_part`, sans identifiant de
prospect) : impossible de dire *qui* a cliqué sur *quoi*, donc impossible de recouper une
facturation contestée autrement qu'en croyant la régie sur parole.

`stats:rollup` construit l'agrégat depuis l'événementiel. Il est **idempotent** : le rejouer sur
une journée déjà traitée doit produire exactement le même résultat.

## Revenus

`platform:report` tire le flux XML de la régie :

```
GET {AFFILIATE_REPORT_URL}?login=…&pass=…&flux=xml&stat=global|cpx&debut=YYYY-MM-DD&fin=YYYY-MM-DD&ids=
→ <campagne> : nom, idc, ids, sid, affichage, clic, dbclic,
               cpl_valide, cpl_attente, cpa_valide, cpa_attente,
               gains_valide, gains_attente
```

Écrit dans `t_platform_report`, clé `(date, idc, ids, sid)`.

**Les identifiants viennent du `.env`** (`AFFILIATE_REPORT_LOGIN`, `AFFILIATE_REPORT_PASSWORD`).
Ils sont en clair dans le code de `meilleursconcours.com`, à six endroits — ne pas répéter cela.

Le `sid` est la clé de jointure entre notre tracking et le leur :
`{sweepstake_id}_{subid}_{email_md5}_{date}`. Changer ce format casse le rapprochement des
revenus pour toutes les journées à venir, et rend les journées passées non comparables.
Le format se modifie donc avec une migration de données, jamais à la volée.

## eCPM

`revenue / impressions × 1000`, calculé sur trois fenêtres (jour, 5 jours, 15 jours), stocké sur
`t_offer` par `stats:rollup`. C'est ce qui pilote l'ordre d'affichage.

Une offre sous le seuil d'impressions n'a pas d'eCPM fiable : elle passe par la branche
d'exploration d'`OfferSelector`, pas par le tri. Sans cela, une offre nouvelle ne serait jamais
affichée et ne pourrait jamais accumuler d'historique.

## Sources de trafic

`subid` (affiliation), `utm_*` (emailing, SEO), `fbclid` / `gclid` / `ttclid` / `msclkid`
(media buy) sont capturés à la première page et **conservés en session** pour toute la durée du
parcours. Un participant arrivé avec un `subid` doit sortir avec le même : c'est ce qui permet
d'attribuer le revenu à la bonne source.
