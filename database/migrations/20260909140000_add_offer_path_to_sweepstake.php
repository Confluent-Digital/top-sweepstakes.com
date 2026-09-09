<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Longueur du parcours d'offres.
 *
 * Les offres ne sont plus presentees toutes ensemble sur une page mais une par
 * une, chacune sur la sienne. Cette colonne dit combien d'etapes au maximum :
 * c'est un arbitrage entre revenu par participation et fatigue du visiteur, et
 * il se regle par concours, en base.
 */
final class AddOfferPathToSweepstake extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_sweepstake')
            ->addColumn('sweepstake_offer_steps', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 4,
                'after' => 'sweepstake_min_age',
                'comment' => 'Nombre maximum d\'offres presentees, une par page. 0 desactive le parcours.',
            ])
            ->update();
    }
}
