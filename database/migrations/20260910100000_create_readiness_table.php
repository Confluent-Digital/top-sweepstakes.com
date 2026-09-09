<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Suivi des reserves d'ouverture.
 *
 * Le catalogue des points ouverts vit dans le code
 * (App\Modules\Admin\Services\ReadinessCatalog et les controles automatiques de
 * ReadinessService) : ce sont des faits techniques et juridiques, pas des
 * donnees d'exploitation.
 *
 * Cette table ne stocke que la DECISION prise sur chacun d'eux : traite, ou
 * risque accepte sciemment, avec sa note, son auteur et sa date. C'est ce qui
 * manque partout ailleurs — savoir non pas qu'un point etait ouvert, mais qui a
 * decide de l'ouvrir quand meme, et pourquoi.
 *
 * Une cle sans ligne ici est ouverte : ne rien ecrire est l'etat par defaut, et
 * un point retire du catalogue laisse une ligne orpheline sans effet.
 */
final class CreateReadinessTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_readiness_ack', [
            'id' => false,
            'primary_key' => 'readiness_ack_key',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Decisions prises sur les reserves d\'ouverture. Aucune donnee personnelle de participant.',
        ])
            ->addColumn('readiness_ack_key', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'Cle du point ouvert, definie dans le catalogue cote code.',
            ])
            ->addColumn('readiness_ack_status', 'enum', [
                'values' => ['open', 'done', 'accepted'],
                'null' => false,
                'default' => 'open',
                'comment' => 'open = a traiter, done = traite, accepted = risque accepte sciemment.',
            ])
            ->addColumn('readiness_ack_note', 'text', [
                'null' => true,
                'comment' => 'Justification. Obligatoire pour accepted et pour tout controle automatique encore au rouge.',
            ])
            ->addColumn('readiness_ack_by', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'default' => null,
                'update' => 'CURRENT_TIMESTAMP',
            ])
            ->create();
    }
}
