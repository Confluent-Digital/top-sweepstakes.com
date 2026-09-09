---
description: ZONE SENSIBLE — Official Rules, TCPA, CAN-SPAM, CCPA, fragments legals, rétention
paths:
  - "src/Modules/Legal/**"
  - "src/Modules/Leads/**"
  - "src/Views/front/**"
---

# Conformité US

Un jeu-concours américain mal cadré n'expose pas à une remarque : il expose à des plaintes
individuelles, à des actions de groupe (TCPA se chiffre par SMS ou appel non consenti) et à des
procédures d'attorney general d'État. Le code doit rendre la conformité **prouvable**, pas
seulement affichée.

## Official Rules — obligatoires, par concours

Éditées dans le back-office (`sweepstake_official_rules_html`), accessibles depuis chaque page du
tunnel. Elles doivent contenir, au minimum :

- **NO PURCHASE NECESSARY** et une **méthode alternative d'entrée** (AMOE) réellement praticable ;
- l'identité et l'adresse du **sponsor** ;
- les **dates** d'ouverture et de clôture ;
- l'**éligibilité** : âge minimum, résidence US, **États exclus** ;
- la **valeur approximative de la dotation** (ARV) ;
- les **probabilités de gain** ;
- le **mode de sélection**, de notification et de publication des gagnants.

Un concours sans Official Rules complètes ne se publie pas. La liste des États exclus dans les
règles et la colonne `sweepstake_excluded_states` doivent dire la même chose — c'est cette
colonne qui refuse effectivement le participant.

⚠️ **Point business à remonter, pas à trancher dans le code** : New York et la Floride imposent
enregistrement et cautionnement au-delà de 5 000 $ d'ARV.

## Preuve de consentement — `t_lead_consent`

Table **en écriture seule**. Aucun `UPDATE`, aucun `DELETE` hors purge RGPD.

Chaque ligne archive : le `consent_type`, le **texte réellement affiché** au participant, son
empreinte, l'URL de la page, l'IP (en `VARCHAR(45)`, IPv6 compris), le user agent et l'horodatage.

Ce que cela règle : dans `meilleursconcours.com`, le texte du consentement n'est nulle part en
base — il est dans un gabarit versionné en git, modifié au fil du temps. Reconstituer ce qu'un
participant a accepté un jour donné y est impossible. Un consentement qu'on ne peut pas prouver
n'existe pas.

**Modifier un texte de consentement ne modifie jamais les lignes déjà écrites.** Un nouveau texte
produit de nouvelles lignes, c'est tout.

## TCPA

Si le téléphone est collecté à des fins d'appel ou de SMS, le consentement doit être :
**exprès, écrit, distinct** de l'acceptation des règles, et non pré-coché. Une case unique qui
mélange règlement, marketing e-mail et démarchage téléphonique ne vaut pas consentement TCPA.

## CAN-SPAM

Lien de désinscription fonctionnel dans chaque e-mail et en pied de page du site, adresse postale
physique, traitement de la désinscription sous dix jours ouvrés. `t_suppression` fait foi :
un e-mail qui y figure ne repart jamais, quelle que soit la source.

## CCPA / CPRA et lois d'État

Lien **« Do Not Sell or Share My Personal Information »** en pied de page, page de demande, et
traitement effectif via `gdpr:purge`. La transmission de données à un partenaire display entre
dans la définition large de « share » : la page de partenaires doit refléter les destinataires réels.

## Fragments legals

`https://legals.confluent-digital.com/?page=<page>&lang=en&domain_name=top-sweepstakes.com`
renvoie un **fragment HTML**, pas une page complète. `LegalController` le met en cache disque
(`LEGALS_CACHE_TTL`) et **dégrade proprement** si le service ne répond pas : une page légale vide
est une non-conformité, pas un incident d'affichage.

État de la couverture en anglais au démarrage du projet : `mentions-legales`,
`conditions-generales`, `politique-vie-privee` et `partenaires` existent ;
**`cookies` n'existe pas en `en`** et il n'y a pas de page `do-not-sell`. Ces fragments sont à
créer dans le dépôt `legals.confluent-digital.com` — travail éditorial et juridique, pas logiciel.

## Rétention

| Donnée | Durée | Mise en œuvre |
|---|---|---|
| Participant actif | 36 mois après la dernière participation | `gdpr:purge` anonymise |
| Preuve de consentement | même durée que le participant | purgée avec lui |
| Événements display | 25 mois | agrégats conservés, événementiel purgé |
| Liste de suppression | sans limite | c'est son objet : ne jamais réémettre vers cet e-mail |

## Après toute modification d'un formulaire ou d'un texte de consentement

Lancer l'agent `compliance-us`.
