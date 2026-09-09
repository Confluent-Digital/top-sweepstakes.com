<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tirages au sort.
 *
 * Deux niveaux, conformes au modele retenu :
 *
 *  - `sweepstake` : a la cloture d'un concours, un finaliste est tire parmi ses
 *    participants. Il ne recoit pas de lot — il entre au tirage annuel.
 *  - `grand_prize` : une fois par an, le gagnant est tire parmi les finalistes
 *    de l'annee. C'est lui qui recoit la dotation.
 *
 * **Un tirage doit etre prouvable, pas seulement effectue.** Contrairement a la
 * plupart des tables de ce depot, celle-ci existe pour repondre a une question
 * posee des mois plus tard, par un participant ou par un regulateur : « comment
 * ce gagnant a-t-il ete choisi ? »
 *
 * D'ou trois colonnes qui n'auraient aucun sens ailleurs :
 *  - `seed` : la graine, tiree AVANT de connaitre les participants ;
 *  - `pool_hash` : l'empreinte de la liste exacte des eligibles, dans l'ordre ;
 *  - `pool_size` : leur nombre.
 *
 * Ensemble, elles permettent de rejouer le tirage et de retrouver le meme
 * gagnant. Un tirage qu'on ne peut pas rejouer n'est pas un tirage : c'est une
 * designation.
 */
final class CreateDrawingTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_drawing', [
            'id' => false,
            'primary_key' => 'drawing_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Tirages au sort. Conservation illimitee : c\'est la preuve du tirage.',
        ])
            ->addColumn('drawing_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('drawing_type', 'enum', [
                'values' => ['sweepstake', 'grand_prize'],
                'null' => false,
            ])
            // Nul pour un tirage annuel : il ne porte pas sur un concours.
            ->addColumn('drawing_id_sweepstake', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('drawing_year', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('drawing_period_start', 'date', ['null' => false])
            ->addColumn('drawing_period_end', 'date', ['null' => false])
            // La graine est tiree avant de lire la liste des participants :
            // l'inverse permettrait de la choisir en fonction du resultat.
            ->addColumn('drawing_seed', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('drawing_pool_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('drawing_pool_size', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('drawing_winners_count', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
            ->addColumn('drawing_executed_at', 'datetime', ['null' => false])
            ->addColumn('drawing_executed_by', 'string', ['limit' => 100, 'null' => false, 'default' => 'cli'])
            ->addColumn('drawing_notes', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            // Un concours ne se tire qu'une fois, une annee ne se tire qu'une fois.
            ->addIndex(['drawing_type', 'drawing_id_sweepstake', 'drawing_year'], [
                'unique' => true,
                'name' => 'uq_drawing_scope',
            ])
            ->addIndex(['drawing_id_sweepstake'])
            ->create();

        $this->table('t_drawing_winner', [
            'id' => false,
            'primary_key' => 'drawing_winner_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Gagnants et suppleants d\'un tirage, dans l\'ordre tire.',
        ])
            ->addColumn('drawing_winner_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('drawing_winner_id_drawing', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('drawing_winner_id_lead', 'integer', ['signed' => false, 'null' => false])
            // Rang 1 = gagnant, 2 et suivants = suppleants. Ils sont tires dans
            // le meme geste : le reglement promet un remplacant « selectionne au
            // hasard », et le designer apres coup ne serait plus du hasard.
            ->addColumn('drawing_winner_rank', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('drawing_winner_status', 'enum', [
                'values' => ['pending', 'notified', 'confirmed', 'forfeited', 'unreachable'],
                'null' => false,
                'default' => 'pending',
            ])
            ->addColumn('drawing_winner_notified_at', 'datetime', ['null' => true])
            ->addColumn('drawing_winner_confirmed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['drawing_winner_id_drawing', 'drawing_winner_rank'], [
                'unique' => true,
                'name' => 'uq_drawing_rank',
            ])
            ->addIndex(['drawing_winner_id_lead'])
            ->addForeignKey('drawing_winner_id_drawing', 't_drawing', 'drawing_id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();

        // Un participant tire au sort doit rester identifiable jusqu'a la
        // remise du lot, et la preuve du tirage doit lui survivre. Sans ce
        // drapeau, gdpr:purge l'anonymiserait a 36 mois comme les autres — et
        // un gagnant anonymise ne peut plus etre contacte ni verifie.
        $this->table('t_lead')
            ->addColumn('lead_drawing_hold', 'boolean', [
                'null' => false,
                'default' => 0,
                'after' => 'lead_anonymized_at',
                'comment' => 'Retenu par un tirage : exclu de la purge automatique.',
            ])
            ->update();
    }
}
