---
description: Ce qu'on teste, comment on le prouve
paths:
  - "tests/**"
---

# Tests

```bash
docker exec topsweepstakes_php composer test    # PHPUnit
docker exec topsweepstakes_php composer stan    # PHPStan niveau 5
docker exec topsweepstakes_php composer cs      # PHPCS PSR-12
curl -s http://127.0.0.1:2032/health            # app + base
```

## Ce qui doit être couvert par des tests unitaires

Les trois services qui décident du revenu et de la conformité :

| Classe | Ce qu'on prouve |
|---|---|
| `TargetingService` | chaque opérateur, y compris les entrées malformées. Une règle non reconnue **refuse** l'offre ; elle ne l'autorise pas. `CiblageService` de `meilleursconcours.com` fait l'inverse (*fail-open*), et deux de ses branches ne retournent rien du tout. |
| `OfferSelector` | filtres, dates, caps jour et total, exploration ε-greedy **déterministe sous graine fixée**, limite par bloc |
| `OfferLinkBuilder` | encodage de chaque paramètre, liste blanche respectée, format du `sid`, base lue depuis la configuration |

Un test qui a besoin de la base utilise une **transaction annulée en fin de test** (`ROLLBACK`),
jamais une base jetée puis recréée.

## Ce qui se prouve à l'exécution, pas en test unitaire

- Une offre rendue produit **une** impression → agent `offer-tracking-verifier`.
- Un formulaire produit les bonnes lignes `t_lead_consent` → agent `compliance-us`.
- Une page répond 200 après une modification de route → smoke `curl`.

## Interdits

- **Aucun test n'appelle la régie ni le flux de reporting.** Un clic ou une conversion de test est
  facturé et pollue le reporting. Les appels sortants vers `cdflow*` et
  `plateforme.confluent-digital.com` sont refusés par `.claude/settings.json`.
- Aucun test n'envoie d'e-mail réel.
- Aucune donnée personnelle réelle dans les fixtures.
