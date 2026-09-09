<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Reglages du site.
 *
 * Ils portent l'identite de l'editeur, telle qu'elle figure dans les mentions
 * legales servies par legals.confluent-digital.com. Les deux doivent dire la
 * meme chose : une adresse en pied de page qui differe de celle des mentions
 * legales est un motif de contestation offert.
 */
final class SiteSettingsSeeder extends AbstractSeed
{
    public function run(): void
    {
        $settings = [
            'site_name' => 'Top Sweepstakes',
            'site_tagline' => 'Win big, enter free',
            'site_intro' => 'Real prizes, no purchase necessary. '
                . 'Open to U.S. residents 18 and older.',
            'site_meta_title' => 'Free Sweepstakes & Gift Card Giveaways',
            'site_meta_description' => 'Enter free sweepstakes for gift cards and prizes. '
                . 'No purchase necessary.',
            'site_company_name' => 'SAS Confluent Digital',
            // Siege social, identique aux mentions legales. SIRET 840 203 939 00045.
            'site_postal_address' => 'Espace Wojo, 15 rue des Cuirassiers, 69003 Lyon, France',
            'site_contact_email' => 'contact@confluent-digital.com',
            'site_empty_message' => 'No sweepstakes are open right now — new ones drop every week.',
        ];

        $statement = $this->getAdapter()->getConnection()->prepare(
            'INSERT INTO t_setting (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($settings as $key => $value) {
            $statement->execute(['k' => $key, 'v' => $value]);
        }
    }
}
