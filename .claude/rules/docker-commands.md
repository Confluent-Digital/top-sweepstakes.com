---
description: Conteneurs, chemins, logs, base
---

# Docker

| Conteneur | Rôle | Port hôte (loopback) |
|---|---|---|
| `topsweepstakes_nginx` | serveur web | 2032 |
| `topsweepstakes_php` | PHP 8.4 FPM + CLI + composer | 7032 |
| `topsweepstakes_mariadb` | MariaDB 11 | 3332 |

Le projet est monté sur **`/data/www/top-sweepstakes.com`** dans les conteneurs — même chemin
qu'à l'extérieur, donc les chemins absolus des messages d'erreur sont directement exploitables.

```bash
docker compose up -d --build
docker compose ps
docker logs --tail=100 topsweepstakes_php
docker logs --tail=100 topsweepstakes_nginx

docker exec topsweepstakes_php composer install
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev
docker exec topsweepstakes_php php bin/cli.php --help

docker exec -it topsweepstakes_mariadb mariadb -u root -p bd_top_sweepstakes
```

## Deux Docker Compose, un seul fichier

La production utilise encore le binaire autonome **docker-compose v1** ; le
developpement, le plugin **docker compose v2+**. Deux consequences dans le
depot :

- `docker-compose.yml` porte une clef `version: "3"`. Le plugin l'ignore avec un
  avertissement d'obsolescence ; v1 en a besoin, car sans elle il lit le fichier
  comme du **format 1**, ou les clefs de premier niveau sont des noms de
  services — il voit un service appele « services » et refuse le fichier. Ne pas
  la retirer tant que la production n'a pas le plugin.
- `bin/setup.sh` et `bin/update.sh` sourcent `bin/lib.sh`, qui detecte l'un ou
  l'autre. Sans cette detection, une machine sans plugin repond
  `unknown shorthand flag: 'd' in -d` — le CLI Docker lit `-d` comme un drapeau
  de premier niveau — un message qui ne nomme ni Compose ni sa cause.

docker-compose v1 n'est plus maintenu depuis juillet 2023 et ne recoit plus de
correctifs de securite. Installer `docker-compose-plugin` sur la production est
la vraie reponse ; la clef `version` n'est qu'un pansement, a retirer ce jour-la.

## Image PHP

`.docker/php-fpm/Dockerfile` étend `docker-registry.confluent-digital.com/php:lp-8.4-fpm`.

L'image du parc est taillée pour des landing pages sans base : **elle n'a pas `pdo_mysql`**
(seulement `pdo_sqlite`). Le Dockerfile l'ajoute, avec `mysqli` et `bcmath`. Ne pas revenir à
l'image nue en pensant simplifier — la connexion échouerait au démarrage.

Son entrypoint d'origine fait des `chmod` sur des chemins propres aux landing pages
(`public/__gestion/config`, `src/Controllers/user`) et sort en erreur sous `set -e` s'ils
n'existent pas. Il est remplacé par `.docker/php-fpm/entrypoint.sh`.

## Deux versions de PHP, une seule qui compte

L'hôte a **PHP 8.3**, le conteneur **PHP 8.4**. Le runtime réel est celui du conteneur.

Conséquence concrète : `new Foo()->bar()` sans parenthèses supplémentaires est valide en 8.4 et
une erreur de parse en 8.3. Un `php -l` lancé depuis l'hôte rejetterait donc du code parfaitement
correct. Les hooks de lint passent par `.claude/hooks/lib-php-lint.sh`, qui utilise
`docker exec topsweepstakes_php php -l` quand le conteneur tourne et ne retombe sur le PHP de
l'hôte qu'à défaut.

Même logique pour `composer`, `phinx` et `phpunit` : toujours dans le conteneur.

## ⚠️ `mariadb -B` échappe les sauts de ligne

Le mode batch (`-B`, souvent utilisé avec `-N` pour scripter) rend les sauts de ligne sous forme de
`\n` **littéraux**. Reprendre cette sortie et la réinjecter — dans un formulaire, un autre
`INSERT` — écrit des `\n` en clair dans la donnée.

C'est arrivé sur les Official Rules du concours de démonstration : la page affichait
`</h2>\n<p>A purchase…`. Rien ne le signale, et cela ne se voit qu'au rendu.

Pour extraire un champ multiligne destiné à être réinjecté, passer par PHP plutôt que par le
client en mode batch :

```bash
docker exec topsweepstakes_php php -r '$pdo = new PDO(...); echo $pdo->query("SELECT ...")->fetchColumn();'
```

Le mode batch reste parfait pour lire des identifiants, des compteurs et des colonnes courtes.

## Logs

- Applicatifs : `logs/app.log` (Monolog).
- Tâches CLI : `logs/tasks/<tache>/YYYYMMDD.log`.
- nginx : dans le conteneur, `/var/log/nginx/topsweepstakes_*.log`.

## SQLyog

MariaDB n'écoute que sur la loopback du serveur. La connexion se fait par **tunnel SSH** sur le
port 3332. Ne jamais exposer 3306 ni 3332 sur `0.0.0.0`.
