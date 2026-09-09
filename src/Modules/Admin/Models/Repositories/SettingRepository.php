<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models\Repositories;

use App\Core\Database;

/**
 * Reglages du site.
 *
 * Ils sont lus sur chaque page publique : le depot les charge donc en une seule
 * requete et les garde pour la duree de la requete HTTP. Sans cela, l'accueil
 * ferait un aller-retour par reglage affiche.
 */
final class SettingRepository
{
    /**
     * Reglages connus et leur valeur par defaut.
     *
     * Une cle absente d'ici n'est ni lue ni ecrite : le formulaire ne peut donc
     * pas creer de reglage fantome, et un reglage retire du code ne traine pas
     * en base en donnant l'illusion d'agir encore.
     *
     * @var array<string,string>
     */
    public const DEFAULTS = [
        'site_name' => '',
        'site_tagline' => 'Enter today\'s sweepstakes',
        'site_intro' => 'Free to enter. No purchase necessary. Open to U.S. residents 18 and older.',
        'site_meta_title' => '',
        'site_meta_description' => '',
        'site_company_name' => '',
        'site_postal_address' => '',
        'site_contact_email' => '',
        'site_favicon' => '',
        'site_og_image' => '',
        'site_empty_message' => 'No sweepstakes are open right now. Please check back soon.',
        // Pages legales affichees en pied de page, au format JSON :
        // [{"page":"privacy","label":"Privacy Policy"}, ...]
        //
        // Pilotable depuis le back-office parce que la couverture de
        // legals.confluent-digital.com varie par langue : `cgu` et `cookies`
        // n'existent pas en anglais, et un lien qui ne mene nulle part vaut
        // moins que pas de lien. Une page dont le contenu se revele faux se
        // retire ici, sans mise en production.
        'site_legal_links' => '[{"page":"privacy","label":"Privacy Policy"},'
            . '{"page":"legal","label":"Legal Notice"},'
            . '{"page":"partners","label":"Marketing Partners"}]',
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private Database $database)
    {
    }

    /**
     * Pages legales affichees en pied de page, telles qu'enregistrees.
     *
     * @return array<string,string> page => libelle affiche
     */
    public function legalLinks(): array
    {
        return self::decodeLegalLinks((string) ($this->all()['site_legal_links'] ?? ''));
    }

    /**
     * SOURCE UNIQUE du decodage de `site_legal_links`.
     *
     * Trois endroits en avaient besoin — le formulaire de reglages, le contexte
     * des gabarits publics et le controle d'ouverture — et chacun s'etait ecrit
     * sa propre boucle. Trois lectures d'un meme JSON finissent par diverger
     * sur un cas limite, et celui-ci decide de ce qu'un participant peut lire
     * avant de consentir.
     *
     * Statique et prenant la chaine brute : le formulaire doit pouvoir decoder
     * ce que l'operateur VIENT de soumettre, pas ce qui est en base. Sinon une
     * erreur de saisie sur un autre champ lui reafficherait l'ancienne
     * selection et lui ferait perdre la sienne.
     *
     * @return array<string,string> page => libelle affiche
     */
    public static function decodeLegalLinks(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $links = [];
        foreach ($decoded as $link) {
            if (is_array($link) && isset($link['page'])) {
                $links[(string) $link['page']] = (string) ($link['label'] ?? $link['page']);
            }
        }
        return $links;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->database->connection()->fetchAllAssociative(
            'SELECT setting_key, setting_value FROM t_setting'
        );

        $stored = [];
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            if (array_key_exists($key, self::DEFAULTS)) {
                $stored[$key] = (string) ($row['setting_value'] ?? '');
            }
        }

        // Une valeur vide en base ne doit pas masquer le defaut : un intitule
        // efface par inadvertance laisserait un trou dans la page publique.
        $merged = self::DEFAULTS;
        foreach ($stored as $key => $value) {
            if (trim($value) !== '') {
                $merged[$key] = $value;
            }
        }

        return $this->cache = $merged;
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->all()[$key] ?? '';
        return $value !== '' ? $value : $default;
    }

    /** @param array<string,string> $settings */
    public function save(array $settings): void
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                if (!array_key_exists($key, self::DEFAULTS)) {
                    continue;
                }
                $connection->executeStatement(
                    'INSERT INTO t_setting (setting_key, setting_value) VALUES (:k, :v)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    ['k' => $key, 'v' => $value]
                );
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->cache = null;
    }
}
