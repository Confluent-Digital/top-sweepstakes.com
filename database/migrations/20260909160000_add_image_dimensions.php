<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dimensions reelles des visuels televerses.
 *
 * Les gabarits ecrivaient `width` et `height` en dur, quelle que soit l'image
 * effectivement deposee : un visuel portrait etait rendu plus haut que la place
 * reservee, et le bouton descendait APRES le chargement — au moment precis ou
 * le visiteur vise. Enregistrer les dimensions a l'upload est la seule facon de
 * reserver la bonne place des le rendu.
 */
final class AddImageDimensions extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_sweepstake')
            ->addColumn('sweepstake_prize_image_width', 'integer', [
                'signed' => false, 'null' => false, 'default' => 0,
                'after' => 'sweepstake_prize_image',
            ])
            ->addColumn('sweepstake_prize_image_height', 'integer', [
                'signed' => false, 'null' => false, 'default' => 0,
                'after' => 'sweepstake_prize_image_width',
            ])
            ->update();

        $this->table('t_offer')
            ->addColumn('offer_image_width', 'integer', [
                'signed' => false, 'null' => false, 'default' => 0,
                'after' => 'offer_image',
            ])
            ->addColumn('offer_image_height', 'integer', [
                'signed' => false, 'null' => false, 'default' => 0,
                'after' => 'offer_image_width',
            ])
            ->update();
    }
}
