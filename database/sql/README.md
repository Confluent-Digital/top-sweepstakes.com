# Scripts SQL ponctuels

Des **données**, pas du code : un concours, un jeu de réglages, une correction
de ligne. Ils passent par ce dossier parce que git est le seul canal déjà en
place entre le poste et la production — pas parce qu'un concours se déploierait.
L'invariant du dépôt tient : le concours vit en base, aucun gabarit n'est créé.

Ils ne sont **pas** des migrations. Phinx gère le schéma et tient son journal ;
ces fichiers ne sont joués qu'à la main, une fois, et restent ici pour que l'on
sache ce qui a été exécuté et quand.

## Exécuter

```bash
cd /data/www/top-sweepstakes.com
git pull
docker exec -i topsweepstakes_mariadb \
    sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" bd_top_sweepstakes' \
    < database/sql/<fichier>.sql
```

Le mot de passe root est lu depuis l'environnement du conteneur : il n'apparaît
ni dans la commande, ni dans l'historique du shell, ni dans `ps`.

## Règles

- Chaque script est **rejouable** : le lancer deux fois ne doit rien casser ni
  rien dupliquer.
- Un concours est créé en **brouillon**. La publication se fait depuis
  `/admin/sweepstakes`, où la validation vérifie les mentions obligatoires — et
  où un humain relit.
- En-tête obligatoire : ce que fait le script, et ce qu'il faut savoir avant de
  le lancer.
