---
description: Conventions Twig, thème, formulaires, back-office
paths:
  - "src/Views/**"
  - "public/dist/**"
---

# Front

## Public

Pas de framework JS. Le tunnel doit rester rapide sur mobile en 4G : c'est du trafic payé,
chaque centaine de millisecondes se paie en taux de conversion.

- CSS et JS dans `public/dist/`, servis avec un cache long ; pas de CDN pour le chemin critique.
- Le **thème** passe par des variables CSS déclarées dans `layout.html.twig` depuis
  `t_sweepstake.theme`. Aucune feuille de style par concours.
- Les images de dotation sont servies en `webp` quand le navigateur l'accepte, avec repli.
- Le JS ne conditionne jamais l'enregistrement d'un participant ni d'un clic : tout fonctionne
  sans lui, en dégradé.

### Le seul script du tunnel

`public/dist/js/legal-modal.js` (moins de 4 Ko, aucune dépendance) ouvre les mentions légales en
**popin**, comme le reste du parc — voir
`template.comparer-changer.fr/templates/1/cadre/legals_modal.twig`, qui fait la même chose avec
jQuery et Bootstrap.

Pourquoi une popin : un participant en cours de saisie qui clique sur « Privacy Policy » ne doit
pas quitter le tunnel.

**Amélioration progressive, et ce n'est pas négociable** : les liens pointent vers de vraies pages
(`/privacy`, `/terms`…). Sans JavaScript, ou si le script échoue, ils naviguent normalement. Une
mention légale rendue inaccessible par un script cassé serait une non-conformité, pas un défaut
d'ergonomie.

Le fragment est chargé à l'ouverture depuis `/legal-fragment/{page}` — l'inclure dans chaque page
coûterait 65 Ko pour un document que la plupart des visiteurs n'ouvriront jamais.

### `partners_viewed`

La popin passe le champ caché `partners_viewed` à 1 quand la liste des destinataires est ouverte,
et la valeur part avec le participant. C'est de la transparence RGPD que rien d'autre ne permet de
reconstituer après coup, et le parc trace la même chose.

**Ce n'est pas un consentement** : ne pas avoir ouvert la liste n'invalide rien, l'avoir ouverte ne
vaut pas accord. C'est une circonstance, au même titre que l'IP.

## Twig

- Échappement automatique. `|raw` est réservé aux champs HTML éditoriaux du back-office
  (`offer_text_html`, `sweepstake_official_rules_html`) et **jamais** appliqué à une donnée
  saisie par un participant.
- Aucune URL de régie écrite dans un gabarit : elle vient de `OfferLinkBuilder`.
- Aucune requête depuis un gabarit. Le contrôleur fournit tout.
- Cache Twig actif en production seulement ; le hook `twig-cache-clear.sh` le vide à chaque
  édition de template en développement.

## Formulaires du tunnel

- Validation côté serveur faisant autorité ; le côté client n'est qu'un confort.
- Les libellés, l'ordre et le caractère obligatoire viennent de `t_sweepstake_field`.
- Les consentements sont **des cases distinctes, jamais pré-cochées**, et leur texte est archivé
  (voir `legal-us.md`).
- Champ honeypot et horodatage d'ouverture de formulaire pour l'anti-bot.

## Back-office

### Réglages du site

`/admin/settings` porte ce qui vaut pour tout le site : nom, textes de l'accueil, métadonnées,
raison sociale, **adresse postale**, favicon, image de partage. Table `t_setting`, en clé/valeur —
ajouter un réglage ne demande donc pas de migration, seulement une entrée dans
`SettingRepository::DEFAULTS`.

Ils sont exposés à **tous** les gabarits sous `site` par `TemplateContextMiddleware`, jamais passés
par un contrôleur : pour l'adresse postale, un oubli n'est pas une gêne d'affichage mais une
mention légale manquante.

**L'adresse postale suit une règle de repli** : celle du sponsor sur les pages d'un concours, celle
du site partout ailleurs. Sans ce repli, `/unsubscribe` n'en affichait aucune — alors que c'est
précisément la page d'atterrissage d'un lien de désinscription, là où CAN-SPAM l'exige.

Une valeur vide en base ne masque jamais le défaut : un intitulé effacé par inadvertance laisserait
un trou sur la seule page indexée du site.

### ⚠️ Le piège des fiches d'édition

Les fiches suivent toutes le même motif : `extract($input)` construit le tableau **complet** des
colonnes, et `update()` les écrit toutes. **Un champ que le contrôleur sait lire mais que le
gabarit n'expose pas est donc écrasé par sa valeur par défaut à chaque enregistrement** — sans
erreur, sans message, sans trace.

Ce n'est pas théorique : `theme_text`, `theme_surface` et `sweepstake_thankyou_html` étaient dans
ce cas. Chaque sauvegarde de la fiche les vidait, et cela ne se découvre qu'en constatant qu'un
texte a disparu, longtemps après.

`tests/Unit/AdminFormCoverageTest.php` verrouille le motif : il compare ce que le contrôleur lit à
ce que le gabarit envoie, et échoue en nommant les champs qui seraient effacés. Il couvre les noms
littéraux et ceux construits par concaténation dans une boucle.

Sa limite, assumée : quand les deux côtés bouclent sur une liste (`theme_{{ key }}`), il vérifie
le préfixe et non que les deux listes soient identiques. **Ajouter une clé au thème demande donc
de la déclarer des deux côtés**, et cela reste à la charge de la revue.


Bootstrap 5 + DataTables + Chart.js. CSRF sur toute méthode mutative (`CsrfMiddleware`).

- Une DataTable filtre côté serveur dès que la table dépasse quelques milliers de lignes.
- Les totaux affichés portent sur le **jeu filtré**, pas sur la page courante.
- « Vide » ne se lit pas « zéro » : un écran sans donnée le dit explicitement.
