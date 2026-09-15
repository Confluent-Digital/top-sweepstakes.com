---
description: Mise en production, checklist, zones à risque
---

# Déploiement

Branche principale : `main`. Une modification passe par une branche, une PR, une revue.

## Deux scripts, deux usages

| Script | Pour quoi | Ce qu'il fait |
|---|---|---|
| `./bin/setup.sh` | **première installation, en développement** | crée le `.env` s'il manque (avec les UID/GID réels), monte les conteneurs, `composer install` **avec** les dépendances de développement, migre en `-e dev` |
| `./bin/install.sh` | **première installation d'une production** | vérifie `.env` (APP_ENV, APP_SECRET, mots de passe, domaine), monte, contrôle que la base existe, `composer install --no-dev`, migre en `-e prod`, rappelle de créer le compte de back-office. **Ne sème rien.** |
| `./bin/update.sh` | **mise à jour d'un environnement existant** | `git pull --ff-only`, `composer install --no-dev --optimize-autoloader`, `phinx migrate -e prod`, purge du cache Twig |

## ⚠️ Les seeds ne vont pas en production

`database/seeds/` ne pose pas des données neutres : les trois seeders publient
des concours — `sweepstake_status = 'published'` — avec des règlements générés
jamais relus par un juriste et des offres portant des identifiants de régie
factices (`DEMO1000`, `ids=996`). Les lancer sur un domaine public mettrait en
ligne de faux jeux-concours américains, immédiatement visibles et immédiatement
opposables.

`App\Core\SeedGuard` les refuse dès que `APP_ENV=production`, avec la marche à
suivre correcte. La dérogation existe — `ALLOW_SEEDS_IN_PRODUCTION=1` — mais
elle doit être écrite, pas déduite.

En production, la première installation se fait donc **sans seed** :

```bash
./bin/install.sh
docker exec topsweepstakes_php php bin/cli.php admin:create \
  --email=… --password='…' --name='…'
# puis les concours se créent depuis /admin/sweepstakes
```

`setup.sh` n'est **pas** le script de mise en production : il installe PHPUnit, PHPStan et PHPCS sur
le serveur, et reconstruit les images. Tester une mise en production, c'est lancer `update.sh` sur un
environnement déjà monté.

Le `composer install` de l'entrypoint suit la **même règle que
`App\Core\Config::isProduction()`** — égalité stricte avec `production`,
`development` par défaut. En production il pose `--no-dev --optimize-autoloader`
et retire donc PHPUnit, PHPStan et PHPCS ; ailleurs il installe tout.

**Phinx est dans `require`, pas `require-dev`** : jouer une migration est une
opération de production. L'y avoir laissé en dépendance de développement faisait
échouer `update.sh` à tous les coups — `composer install --no-dev` retirait
Phinx à la ligne précédant `vendor/bin/phinx migrate`. Ne pas l'y remettre.

Les deux sourcent `bin/lib.sh`, qui vérifie que le démon Docker répond et détecte Docker Compose —
plugin `docker compose` ou binaire `docker-compose`. Sans cette détection, un serveur où le plugin
manque répond `unknown shorthand flag: 'd' in -d`, un message du CLI Docker qui ne nomme ni Compose
ni sa cause et fait chercher le problème dans le script.

## Avant de committer

1. `php -l` sur les fichiers touchés — les hooks le font déjà, en direct et à l'arrêt.
2. `composer cs` et `composer stan` propres.
3. `composer test` vert.
4. Aucun secret dans le diff — le hook `no-secrets.sh` bloque, mais relire tout de même.
5. Selon la zone touchée :
   - chaîne display → agent `offer-tracking-verifier` ;
   - formulaire ou texte de consentement → agent `compliance-us` ;
   - reste → agent `php-code-reviewer`.

## nginx de l'hôte

Le site tourne derrière **deux** nginx : celui du conteneur, qui parle à PHP-FPM
et n'écoute que sur `127.0.0.1:${DOCKER_NGINX_PORT}`, et celui de l'hôte, qui
porte le nom de domaine et relaie.

```bash
./bin/make-vhost.sh --print      # voir sans écrire
./bin/make-vhost.sh              # écrit /data/nginx/<APP_DOMAIN>.conf
sudo systemctl reload nginx
```

Le domaine et le port sont lus dans le `.env`, la limite d'envoi dans
`uploads.ini` : rien n'est écrit en dur, et le fichier produit suit
l'environnement.

Deux choses que ce vhost doit faire et qu'on ne peut pas omettre :

- **`X-Real-IP` écrasé avec `$remote_addr`.** L'IP du participant est archivée
  dans les preuves de consentement (TCPA, CAN-SPAM). Sans cet en-tête, chaque
  preuve porterait l'adresse du proxy et ne prouverait rien. Il est *écrasé* et
  non relayé, car un client peut envoyer ce qu'il veut — et
  `ConsentRecorder::clientIp()` lit `X-Real-IP` en premier, précisément pour ça.
- **`client_max_body_size` au moins égal à `post_max_size`.** En dessous, nginx
  répond 413 avant que PHP ne voie le fichier, et le téléversement d'un visuel
  de concours échoue sans message exploitable.

### `www` → apex

Par défaut le vhost généré redirige `www.<domaine>` vers le domaine nu, en 301,
dans un **deuxième bloc serveur**. `--canonical www` inverse, `--no-canonical`
sert les deux noms sans rediriger.

Deux raisons de le faire ici plutôt que chez le registrar :

- **La query string doit survivre.** `$request_uri` porte le chemin *et* les
  paramètres. Treize paramètres d'acquisition (`subid`, `utm_*`, `gclid`,
  `fbclid`, `clickid`…) sont lus à la première page, figés en session par
  `VisitorContext`, et le `subid` finit dans le `sid` envoyé à la régie. Une
  redirection qui les perdrait rendrait le revenu inattribuable — et personne ne
  s'en apercevrait avant le rapprochement de fin de mois.
- **Deux noms servant le même site, c'est deux sessions** pour un même visiteur
  selon le lien cliqué, en plus du contenu dupliqué pour les moteurs.

La redirection est dans un `location /`, **pas** dans un `return` au niveau du
serveur : celui-ci s'exécute avant le choix du `location` et court-circuiterait
la validation ACME du nom redirigé — le certificat ne couvrirait alors qu'un
seul hôte.

Le DNS, lui, ne redirige pas : il résout un nom en adresse. Les deux noms
doivent simplement pointer sur le serveur (un `A` sur l'apex, un `A` ou `CNAME`
sur `www`).

Le TLS reste à faire ; la marche à suivre est en commentaire en fin de fichier
généré. **Point de vigilance** : le nginx du conteneur pose
`fastcgi_param HTTPS off`. Tant qu'il n'est pas rendu conditionnel à
`X-Forwarded-Proto`, PHP se croira en clair et les cookies de session n'auront
pas l'attribut `Secure`.

## Avant d'ouvrir le site au trafic

`/admin/readiness` — **Réserves d'ouverture** — liste ce qui n'est pas fait : les contrôles
automatiques (offre sans `idv`, concours publié sans règlement, page légale qui ne rend rien,
CGU anglaises qui n'en sont pas, absence de mention CCPA) et les points déclarés dans
`ReadinessCatalog` (règlement jamais relu par un juriste, lots du plan non développés).

Le verdict de cet écran fait foi : **aucun budget d'acquisition tant qu'il reste une réserve
bloquante non arbitrée**. Une réserve peut être fermée — « traité » ou « risque accepté » — mais
la décision est datée, signée et journalisée dans `t_admin_log`, et un contrôle automatique
encore au rouge reste affiché au rouge.

Un point ouvert que le code pourrait constater n'a rien à faire dans `ReadinessCatalog` :
il devient un contrôle dans `ReadinessService`.

## Journaux

`logs/app.log` porte desormais la **ligne de requete** devant chaque erreur :

```
GET /robots.txt — 404 Not Found Type: Slim\Exception\HttpNotFoundException ...
```

Sans elle, un 404 ne donnait que quinze lignes de pile a travers les middlewares
de Slim, sans jamais nommer l'URL demandee : impossible de savoir s'il fallait
corriger quelque chose ou classer sans importance. C'est `App\Core\ErrorHandler`,
pose par `setDefaultErrorHandler()` dans `src/app.php`.

Les 404 courants d'un site public sont traites en amont, sans atteindre PHP :
`favicon.ico` et les autres extensions statiques par le bloc `location ~*` du
nginx du conteneur, `robots.txt` par un fichier reel dans `public/`.

## Après la mise en production

1. `curl -s https://top-sweepstakes.com/health` → `status: ok` **et** `database: ok`.
2. Parcours réel d'un concours actif, jusqu'à la page de remerciement.
3. Vérifier qu'une impression et un clic se sont bien inscrits dans `t_offer_event`.
4. Surveiller `logs/app.log` pendant les premières minutes.

## Zones à risque

| Changement | Ce qui casse si on se trompe |
|---|---|
| Format du `sid` | le rapprochement des revenus avec la régie, pour toujours |
| `OfferLinkBuilder` | les clics sortent mal attribués, ou fuitent des données personnelles |
| Comptage des impressions | les eCPM, donc l'ordre d'affichage, donc le revenu |
| Ordre des routes | `/{slug}` déclarée trop tôt avale `/admin`, `/out`, les pages légales |
| Texte de consentement | la valeur probante de tout ce qui est collecté ensuite |
| Migration sur une table peuplée | perte de données silencieuse |

## Retour arrière

`phinx rollback -e prod` puis `git revert`. Une migration qui ne sait pas revenir en arrière doit
le dire explicitement dans son `down()`, avec la procédure manuelle en commentaire.
