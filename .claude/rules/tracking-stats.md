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

Deux clés relient le flux à nos données :

- **`idc`** rapproche une ligne du flux d'une de **nos offres** (`offer_platform_idc`).
  `idv` désigne la créa et sert à construire le lien de sortie ; il n'apparaît pas dans le flux.
- Le **`sid`** fournit le concours et la source :
`{sweepstake_id}_{subid}_{email_md5}_{date}`. Changer ce format casse le rapprochement des
revenus pour toutes les journées à venir, et rend les journées passées non comparables.
Le format se modifie donc avec une migration de données, jamais à la volée.

## eCPM

`revenue / impressions × 1000`, calculé sur trois fenêtres (jour, 5 jours, 15 jours), stocké sur
`t_offer` par `stats:rollup`. C'est ce qui pilote l'ordre d'affichage.

Une offre sous le seuil d'impressions n'a pas d'eCPM fiable : elle passe par la branche
d'exploration d'`OfferSelector`, pas par le tri. Sans cela, une offre nouvelle ne serait jamais
affichée et ne pourrait jamais accumuler d'historique.

## Qualité des numéros

Le téléphone est **obligatoire** sur les formulaires : c'est un arbitrage métier, il porte la
rémunération. Son taux de collecte vaut donc toujours 100 % et n'apprend rien.

Ce qui se mesure, c'est la **part de numéros démarchables** — ceux assortis d'un consentement
`tcpa_phone` accordé. Un numéro sans ce consentement ne peut être ni appelé ni contacté par SMS ;
il ne vaut que pour le dédoublonnage.

L'indicateur est sur `/admin` (30 jours) et par source sur `/admin/stats/sources`. Il varie
fortement d'une source à l'autre : c'est ce qui distingue une source qui livre ce pour quoi on la
paie d'une source qui livre du volume.

## Sources de trafic

L'écran `/admin/stats/sources` part des **participations**, pas des revenus. Une source qui
apporte du volume sans rien rapporter doit apparaître : c'est précisément celle qui pose question.
Partir de `t_offer_revenue_daily` la rendrait invisible.

`subid` (affiliation), `utm_*` (emailing, SEO), `fbclid` / `gclid` / `ttclid` / `msclkid`
(media buy) sont capturés à la première page et **conservés en session** pour toute la durée du
parcours. Un participant arrivé avec un `subid` doit sortir avec le même : c'est ce qui permet
d'attribuer le revenu à la bonne source.

## Cron

`config/cron` porte la crontab de référence : `platform:report` puis `stats:rollup`, toutes les
heures, la seconde décalée de quinze minutes pour qu'elle lise des revenus fraîchement écrits.

Les deux tâches remontent **trois jours** par défaut, pas seulement la veille. La régie révise ses
chiffres pendant plusieurs jours — validations, annulations — et l'écriture étant un upsert,
rejouer une journée est sans effet de bord. Un traitement qui ne regarderait que la veille perdrait
définitivement toute révision arrivée après coup.

`run-one` évite le recouvrement de deux exécutions.

## Revenu non attribué

Une ligne du flux dont l'`idc` ne correspond à aucune offre, ou dont le `sid` ne se lit pas, est
comptée comme **non attribuée** et journalisée dans `logs/app.log` — jamais répartie au prorata.
Répartir reviendrait à fabriquer des chiffres qui serviraient ensuite à arbitrer l'affichage.

Le message de fin de `stats:rollup` signale le montant concerné. Un montant qui grossit veut dire
qu'une offre a été créée côté régie sans que son `idc` soit renseigné ici, ou que le format du
`sid` a changé.
