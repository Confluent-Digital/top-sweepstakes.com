<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Participants, preuve de consentement et liste de suppression.
 *
 * Zone sensible : voir .claude/rules/legal-us.md pour les durees de
 * conservation et les obligations TCPA / CAN-SPAM / CCPA.
 */
final class CreateLeadTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_lead', [
            'id' => false,
            'primary_key' => 'lead_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Participants. Donnees personnelles, conservation 36 mois (gdpr:purge).',
        ])
            ->addColumn('lead_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('lead_uniqid', 'string', ['limit' => 40, 'null' => false])
            // Identite
            ->addColumn('lead_email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('lead_email_md5', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('lead_first_name', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            ->addColumn('lead_last_name', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            ->addColumn('lead_dob', 'date', ['null' => true])
            ->addColumn('lead_gender', 'enum', [
                'values' => ['unknown', 'male', 'female', 'other'],
                'null' => false,
                'default' => 'unknown',
            ])
            // Adresse US
            ->addColumn('lead_address', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_city', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            ->addColumn('lead_state', 'string', ['limit' => 2, 'default' => '', 'null' => false])
            ->addColumn('lead_zip', 'string', ['limit' => 10, 'default' => '', 'null' => false])
            ->addColumn('lead_country', 'string', ['limit' => 2, 'default' => 'US', 'null' => false])
            ->addColumn('lead_phone', 'string', ['limit' => 20, 'default' => '', 'null' => false])
            ->addColumn('lead_phone_md5', 'string', ['limit' => 32, 'default' => '', 'null' => false])
            // Contexte de capture. VARCHAR(45) et non INT : ip2long ne sait pas
            // representer IPv6, et une preuve de consentement sans IP ne vaut rien.
            ->addColumn('lead_ip', 'string', ['limit' => 45, 'default' => '', 'null' => false])
            ->addColumn('lead_user_agent', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            ->addColumn('lead_device', 'enum', [
                'values' => ['desktop', 'mobile', 'tablet', 'unknown'],
                'null' => false,
                'default' => 'unknown',
            ])
            ->addColumn('lead_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('lead_id_variant', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            // Attribution de la source. Conserves en session tout le parcours :
            // un participant arrive avec un subid doit sortir avec le meme.
            ->addColumn('lead_source', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            ->addColumn('lead_subid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_clickid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_utm_source', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_utm_medium', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_utm_campaign', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_utm_term', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_utm_content', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_fbclid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_gclid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_ttclid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_msclkid', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('lead_referer', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            // Qualite et cycle de vie
            ->addColumn('lead_status', 'enum', [
                'values' => ['partial', 'complete', 'rejected'],
                'null' => false,
                'default' => 'partial',
            ])
            ->addColumn('lead_email_verified', 'enum', [
                'values' => ['pending', 'valid', 'invalid', 'risky'],
                'null' => false,
                'default' => 'pending',
            ])
            ->addColumn('lead_phone_verified', 'enum', [
                'values' => ['pending', 'valid', 'invalid'],
                'null' => false,
                'default' => 'pending',
            ])
            ->addColumn('lead_quality_score', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('lead_test', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('lead_unsubscribed_at', 'datetime', ['null' => true])
            ->addColumn('lead_anonymized_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['lead_uniqid'], ['unique' => true])
            // Cle fonctionnelle : un participant par concours. Pas d'unicite
            // globale sur l'email — elle ecraserait la date du premier
            // consentement a chaque nouvelle participation.
            ->addIndex(['lead_id_sweepstake', 'lead_email'], ['unique' => true])
            ->addIndex(['lead_email_md5'])
            ->addIndex(['created_at', 'lead_status'], ['name' => 'idx_created_status'])
            ->addIndex(['lead_subid'])
            ->addIndex(['lead_state'])
            ->addForeignKey('lead_id_sweepstake', 't_sweepstake', 'sweepstake_id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE',
            ])
            ->create();

        // Table EN ECRITURE SEULE. Aucun UPDATE, aucun DELETE hors purge RGPD.
        // Modifier un texte de consentement ne modifie jamais les lignes deja
        // ecrites : un nouveau texte produit de nouvelles lignes.
        $this->table('t_lead_consent', [
            'id' => false,
            'primary_key' => 'lead_consent_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Preuve de consentement, immuable. Purgee avec le participant.',
        ])
            ->addColumn('lead_consent_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('lead_consent_id_lead', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('lead_consent_id_sweepstake', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('lead_consent_type', 'enum', [
                'values' => ['rules', 'marketing_email', 'tcpa_phone', 'tcpa_sms', 'partners'],
                'null' => false,
            ])
            ->addColumn('lead_consent_granted', 'boolean', ['null' => false, 'default' => 0])
            // Le texte REELLEMENT affiche, pas une reference vers un gabarit.
            // Sans lui, impossible de reconstituer ce qui a ete accepte.
            ->addColumn('lead_consent_text', 'text', ['null' => false])
            ->addColumn('lead_consent_text_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('lead_consent_page_url', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            ->addColumn('lead_consent_ip', 'string', ['limit' => 45, 'default' => '', 'null' => false])
            ->addColumn('lead_consent_user_agent', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addIndex(['lead_consent_id_lead'])
            ->addIndex(['lead_consent_type'])
            ->addForeignKey('lead_consent_id_lead', 't_lead', 'lead_id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE',
            ])
            ->create();

        // Conservation illimitee : c'est l'objet meme de la table. Un e-mail qui
        // y figure ne doit jamais etre reintroduit, quelle que soit la source.
        $this->table('t_suppression', [
            'id' => false,
            'primary_key' => 'suppression_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Desinscriptions et demandes de suppression. Hachee, conservation illimitee.',
        ])
            ->addColumn('suppression_id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('suppression_email_md5', 'string', ['limit' => 32, 'default' => '', 'null' => false])
            ->addColumn('suppression_phone_md5', 'string', ['limit' => 32, 'default' => '', 'null' => false])
            ->addColumn('suppression_type', 'enum', [
                'values' => ['unsubscribe', 'delete_request', 'do_not_sell', 'complaint', 'bounce'],
                'null' => false,
                'default' => 'unsubscribe',
            ])
            ->addColumn('suppression_source', 'string', ['limit' => 100, 'default' => '', 'null' => false])
            ->addColumn('suppression_processed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->addIndex(['suppression_email_md5'])
            ->addIndex(['suppression_phone_md5'])
            ->addIndex(['suppression_type', 'suppression_processed_at'])
            ->create();
    }
}
