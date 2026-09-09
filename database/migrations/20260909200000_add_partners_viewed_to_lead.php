<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le participant a-t-il ouvert la liste des destinataires ?
 *
 * C'est une information de transparence RGPD/CPRA qu'aucune autre donnee ne
 * permet de reconstituer apres coup : on sait quel texte a ete affiche
 * (`t_lead_consent`), mais pas si la liste des partenaires a ete consultee.
 * Le reste du parc trace la meme chose.
 *
 * Ce n'est PAS un consentement : ne pas avoir ouvert la liste n'invalide rien,
 * l'avoir ouverte ne vaut pas accord. C'est une circonstance, au meme titre que
 * l'IP ou le user agent.
 */
final class AddPartnersViewedToLead extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_lead')
            ->addColumn('lead_partners_viewed', 'boolean', [
                'null' => false,
                'default' => 0,
                'after' => 'lead_user_agent',
            ])
            ->update();
    }
}
