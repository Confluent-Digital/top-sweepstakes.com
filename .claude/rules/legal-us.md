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

Un concours sans Official Rules complètes ne se publie pas — et **« non vide » ne suffit pas**
comme critère. Des règles tronquées en plein mot ont été publiées sans que rien ne le signale.
`SweepstakeAdminController::validateForPublication()` exige donc, pour passer en `published` :

- des Official Rules d'au moins 1 500 caractères de texte, portant explicitement
  *NO PURCHASE NECESSARY*, l'*alternate method of entry* et les *odds of winning* ;
- un sponsor nommé **et son adresse postale** — elle sert deux fois : CAN-SPAM, et l'AMOE, qui
  est l'adresse où l'on poste une participation par courrier ;
- une date d'ouverture **et une date de clôture**. Sans dates, la période de participation ne se
  délimite pas, donc l'éligibilité d'un tirage ne se justifie pas — et un concours sans date de
  fin ne se ferme jamais : il continue de collecter.

La liste des États exclus dans les règles et la colonne `sweepstake_excluded_states` doivent dire
la même chose — c'est cette colonne qui refuse effectivement le participant.

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

La seule écriture autorisée sur cette table en dehors de l'insertion est la **suppression par
`gdpr:purge`**, en même temps que l'anonymisation du participant. Garder la preuve après avoir
anonymisé la fiche reviendrait à conserver l'IP, le user agent et l'URL — précisément ce qu'on
vient d'effacer — dans une autre table, en croyant s'en être débarrassé. La preuve n'est donc
disponible que tant que le participant l'est.

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

**Le service est appelé en interne en priorité.** `legalscd_nginx` est joignable par le réseau
`comparer-changer-network`, que le conteneur PHP rejoint : pas de sortie Internet, pas de
négociation TLS. C'est ce que font les autres sites du parc — voir
`template.comparer-changer.fr/public/legals.php`. L'URL publique reste le repli, configurée par
`LEGALS_BASE_URL`.

`http://legalscd_nginx/?page=<page>&lang=en&domain_name=top-sweepstakes.com`
renvoie un **fragment HTML**, pas une page complète. `LegalController` le met en cache disque
(`LEGALS_CACHE_TTL`) et **dégrade proprement** si le service ne répond pas : une page légale vide
est une non-conformité, pas un incident d'affichage.

⚠️ **Un code 200 ne prouve pas qu'on a reçu un texte légal.** Quand un fragment n'existe pas dans
une langue donnée, le service répond **200 avec un avertissement PHP** et une trace Xdebug
contenant le chemin absolu de son serveur. Sans contrôle, cette trace s'affichait à la place de la
politique cookies et était mise en cache pour toute la durée du TTL — le repli ne se déclenchait
jamais, puisque le contenu n'était pas considéré comme absent. Deux torts en un : page légale
vide, et divulgation d'un chemin interne.

`LegalContentService::looksLikeContent()` écarte donc les réponses trop courtes (< 200 caractères)
et celles portant un marqueur d'erreur (`xdebug-error`, `Warning:`, `Fatal error`, `Call Stack`,
`/var/www/`), journalise, et retombe sur le cache périmé. **Ne pas assouplir ce contrôle** : c'est
la seule chose qui distingue un fragment d'une page d'erreur, le code HTTP étant identique.

État de la couverture en anglais au démarrage du projet : `mentions-legales`,
`conditions-generales`, `politique-vie-privee` et `partenaires` existent ;
**`cookies` n'existe pas en `en`** et il n'y a pas de page `do-not-sell`. Ces fragments sont à
créer dans le dépôt `legals.confluent-digital.com` — travail éditorial et juridique, pas logiciel.

## Les liens légaux se pilotent, ils ne sont pas codés

`/admin/settings` choisit **quels documents légaux figurent en pied de page**, et sous quel
libellé. Aucun texte légal n'est écrit dans ce dépôt : tout vient du service mutualisé.

C'est configurable parce que **sa couverture varie par langue et que son contenu peut être faux** :

- `cookies` et `cgu` n'existent pas en anglais — le service y répond 200 avec un avertissement PHP,
  écarté par `looksLikeContent()` ;
- au moment d'écrire ceci, **`conditions-generales` sert la politique de confidentialité** en
  anglais, en espagnol et en néerlandais. Le fichier `src/conditions-generales/en/text.html` de
  `legals.confluent-digital.com` commence par « CONFLUENT DIGITAL PERSONAL DATA PROTECTION
  POLICY ». Ce n'est pas propre à ce site : tout le parc qui affiche ses CGU dans ces langues sert
  le mauvais document.

L'écran teste la disponibilité en direct, mais **« répond » ne veut pas dire « bon document »** :
la vérification est technique, elle ne lit pas ce qu'elle reçoit. Un document se relit avant d'être
affiché.

⚠️ **Un document cité dans un texte de consentement doit rester atteignable.** Les textes de
`ConsentCatalog` renvoient aux Official Rules, aux Terms of Service et à la Privacy Policy : retirer
l'un de ces liens fait accepter au participant un document qu'il ne peut pas lire. L'écran des
réglages le signale ; le corriger passe soit par le contenu chez legals, soit par le texte de
consentement — et ce second choix est juridique, pas technique.

## Identité de l'éditeur

Elle est saisie dans `/admin/settings` et doit dire **exactement** la même chose que les mentions
légales servies par le service de contenus : une adresse en pied de page qui diffère de celle des
mentions légales est un motif de contestation offert.

Valeurs de référence, telles qu'elles figurent dans `mentions-legales` :
SAS Confluent Digital, Espace Wojo, 15 rue des Cuirassiers, 69003 Lyon — SIRET 840 203 939 00045 —
`contact@confluent-digital.com`.

⚠️ **Cette adresse est française, et le site s'adresse au marché américain.** CAN-SPAM n'exige pas
une adresse aux États-Unis, donc le pied de page est conforme. Mais l'**AMOE** des Official Rules
est l'adresse où l'on poste une participation par courrier : la faire partir en France est légal,
inhabituel, et se discute — c'est un arbitrage à porter au commanditaire, pas une décision de code.
C'est pourquoi l'adresse du **sponsor du concours** prime sur celle du site sur les pages d'un
concours.

## Rétention

| Donnée | Durée | Mise en œuvre |
|---|---|---|
| Participant actif | 36 mois après la dernière participation | `gdpr:purge` anonymise |
| Preuve de consentement | même durée que le participant | **supprimée** avec lui par `gdpr:purge` |
| Événements display | 25 mois | agrégats conservés, événementiel purgé |
| Liste de suppression | sans limite | c'est son objet : ne jamais réémettre vers cet e-mail |

## Après toute modification d'un formulaire ou d'un texte de consentement

Lancer l'agent `compliance-us`.
