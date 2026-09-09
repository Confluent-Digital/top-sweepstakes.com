# top-sweepstakes.com

Moteur de **jeux-concours US** avec coregistration en display.

Un participant arrive sur la landing d'un concours, remplit un formulaire en deux étapes, puis se
voit proposer des offres partenaires. Chaque clic sur une offre sort vers la régie d'affiliation
et constitue le revenu du site. Les participants restent en base locale : rien n'est transmis à
une plateforme tierce.

**Le principe directeur : un concours se crée en base, jamais en déployant du code.**

## Stack

PHP 8.4 · Slim 4 · Twig 3 · PHP-DI 7 · Doctrine DBAL 3 · Phinx · MariaDB 11 · Docker

## Démarrer

```bash
cp .env.example .env      # renseigner APP_SECRET, DB_PASSWORD, DB_ROOT_PASSWORD
./bin/setup.sh            # build, up, composer install, migrations
curl -s http://127.0.0.1:2032/health
```

## Au quotidien

```bash
docker exec topsweepstakes_php composer test    # PHPUnit
docker exec topsweepstakes_php composer stan    # PHPStan niveau 5
docker exec topsweepstakes_php composer cs      # PHPCS PSR-12
docker exec topsweepstakes_php php vendor/bin/phinx migrate -e dev
docker exec topsweepstakes_php php bin/cli.php --help
./bin/update.sh                                  # mise à jour d'un environnement existant
```

Ports, tous sur la loopback : nginx **2032**, PHP-FPM **7032**, MariaDB **3332**.
SQLyog se connecte à MariaDB par tunnel SSH sur le port 3332.

## Documentation

`CLAUDE.md` donne l'architecture et l'index des règles.
`.claude/rules/` porte les invariants, sujet par sujet — en particulier `offers-display.md`
(la chaîne de monétisation) et `legal-us.md` (conformité sweepstakes, TCPA, CAN-SPAM, CCPA),
qui sont les deux zones où une erreur ne se voit pas à l'écran.
