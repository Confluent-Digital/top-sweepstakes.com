<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

/**
 * Ce qui n'est pas fait, et que le code ne peut pas constater tout seul.
 *
 * ReadinessService verifie a l'execution tout ce qui est verifiable : une offre
 * sans idv, un concours publie sans reglement, une page legale qui ne repond
 * pas. Restent les points qui ne laissent aucune trace en base — un texte
 * jamais relu par un juriste, un lot du plan qui n'a pas ete developpe, une
 * decision produit en attente. Sans cette liste, ils ne vivent que dans la
 * memoire de celui qui les a rencontres.
 *
 * Les declarer ici les met sous les yeux de l'exploitant a chaque connexion, et
 * les rend refutables : on les traite, ou on accepte le risque en le signant.
 *
 * Une entree qui devient verifiable a sa place dans ReadinessService, pas ici.
 */
final class ReadinessCatalog
{
    public const BLOCKER = 'blocker';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /** Ordre d'affichage et libelles des severites. */
    public const SEVERITIES = [
        self::BLOCKER => 'Bloquant',
        self::WARNING => 'À traiter',
        self::INFO => 'Décision en attente',
    ];

    /** Statuts possibles d'une decision. */
    public const STATUSES = [
        'open' => 'À traiter',
        'done' => 'Traité',
        'accepted' => 'Risque accepté',
    ];

    /**
     * Points ouverts declares.
     *
     * `why` dit ce que ca coute de ne rien faire, `action` ce qu'il faut faire,
     * `owner` qui peut le faire. Les trois comptent : un point ouvert sans
     * destinataire ne se ferme jamais.
     *
     * Ces textes sont lus a l'ecran par un exploitant : ils sont accentues,
     * contrairement aux commentaires du depot.
     *
     * @var list<array{key:string, severity:string, area:string, title:string,
     *                 why:string, action:string, owner:string, link:string}>
     */
    public const DECLARED = [
        [
            'key' => 'legal.rules_review',
            'severity' => self::BLOCKER,
            'area' => 'Légal',
            'title' => 'Official Rules jamais relues par un juriste',
            'why' => 'Les règlements en ligne ont été rédigés par génération à partir des colonnes du '
                . 'concours. Ils sont structurellement complets, mais aucun conseil américain ne les a '
                . 'validés. Un règlement fautif se conteste après le tirage, quand il est trop tard.',
            'action' => 'Faire relire le règlement d\'un concours par un conseil américain, puis reporter '
                . 'ses corrections sur le générateur de règlements avant d\'en publier d\'autres.',
            'owner' => 'Juridique',
            'link' => '/admin/sweepstakes',
        ],
        [
            'key' => 'legal.rules_versioning',
            'severity' => self::WARNING,
            'area' => 'Légal',
            'title' => 'Le règlement accepté n\'est pas archivé',
            'why' => 't_lead_consent archive le texte de la case cochée, pas le règlement en vigueur ce '
                . 'jour-là. Si le règlement est modifié après coup, plus rien ne dit à quoi un participant '
                . 'a réellement consenti.',
            'action' => 'Versionner sweepstake_official_rules_html et référencer la version dans la preuve '
                . 'de consentement.',
            'owner' => 'Développement',
            'link' => '',
        ],
        [
            'key' => 'legal.do_not_sell_source',
            'severity' => self::WARNING,
            'area' => 'Légal',
            'title' => 'La page Do Not Sell n\'est adossée à aucun texte validé',
            'why' => 'Le formulaire « Do Not Sell or Share My Personal Information » est un gabarit local. '
                . 'Le service de contenus juridiques ne fournit pas cette page : le texte affiché n\'engage '
                . 'personne et n\'a pas été relu.',
            'action' => 'Faire écrire la page dans le dépôt legals, puis la consommer comme les autres '
                . 'mentions.',
            'owner' => 'Dépôt legals',
            'link' => '/do-not-sell',
        ],
        [
            'key' => 'privacy.ip_logging',
            'severity' => self::WARNING,
            'area' => 'Données personnelles',
            'title' => 'IP journalisée au rejet anti-robot, sans durée de conservation',
            'why' => 'Un rejet du filtre anti-robot écrit l\'adresse IP dans logs/app.log. C\'est une donnée '
                . 'personnelle, conservée hors de la base, que gdpr:purge ne touche pas et qu\'aucune durée '
                . 'ne borne.',
            'action' => 'Fixer une durée de rétention des journaux applicatifs et la faire appliquer par la '
                . 'rotation, ou cesser d\'y écrire l\'IP.',
            'owner' => 'Développement',
            'link' => '',
        ],
        [
            'key' => 'leads.export_jarvis',
            'severity' => self::INFO,
            'area' => 'Produit',
            'title' => 'Envoi des participants vers Jarvis non réalisé',
            'why' => 'Décision prise à la conception : les participants restent en base locale. Rien n\'est '
                . 'poussé vers un tiers. Ce n\'est pas un défaut, c\'est un choix — mais il est en attente '
                . 'de réexamen.',
            'action' => 'Trancher si et quand les participants doivent partir vers Jarvis, et sous quelle '
                . 'base légale.',
            'owner' => 'Décision',
            'link' => '',
        ],
        [
            'key' => 'acquisition.pixels',
            'severity' => self::INFO,
            'area' => 'Acquisition',
            'title' => 'Pixels media buy et conversions S2S non développés',
            'why' => 'Le lot 4 du plan n\'a pas été réalisé : aucune conversion n\'est remontée vers '
                . 'Facebook, TikTok ou Google. Une campagne achetée ne peut donc pas s\'optimiser sur la '
                . 'participation.',
            'action' => 'Développer le lot 4 avant d\'ouvrir un budget d\'achat de trafic.',
            'owner' => 'Développement',
            'link' => '',
        ],
        [
            'key' => 'drawings.grand_prize_prize',
            'severity' => self::INFO,
            'area' => 'Tirages',
            'title' => 'Lot du super gagnant : question non tranchée',
            'why' => 'Le tirage annuel attribue aujourd\'hui la dotation du concours dont le finaliste est '
                . 'issu. L\'autre modèle — un lot unique annoncé d\'avance — change ce qui doit figurer dans '
                . 'les Official Rules.',
            'action' => 'Choisir le modèle avant la première collecte réelle : après, le règlement affiché '
                . 'aux participants ne peut plus être changé.',
            'owner' => 'Décision',
            'link' => '/admin/drawings',
        ],
    ];

    /**
     * Documents nommes dans les textes de consentement.
     *
     * Source unique : le back-office s'en sert pour signaler un document cite
     * mais absent du pied de page, et le controle d'ouverture pour le compter
     * comme reserve. Les deux doivent parler des memes documents.
     *
     * @return array<string,string> libelle affiche => page du service de contenus
     */
    public static function citedDocuments(): array
    {
        return [
            'Terms of Service' => 'terms',
            'Privacy Policy' => 'privacy',
        ];
    }
}
