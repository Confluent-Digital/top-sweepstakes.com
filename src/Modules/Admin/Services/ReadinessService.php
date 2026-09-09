<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Config;
use App\Modules\Admin\Models\Repositories\ReadinessRepository;
use App\Modules\Admin\Models\Repositories\SettingRepository;
use App\Modules\Drawings\Models\Repositories\DrawingRepository;
use App\Modules\Leads\Services\UsStates;
use App\Modules\Legal\Services\LegalContentService;
use App\Modules\Sweepstakes\Services\OfficialRules;
use Psr\Log\LoggerInterface;

/**
 * Reserves d'ouverture : ce qui n'est pas fait, vu depuis le back-office.
 *
 * Un site de jeux-concours americain se met en ligne avec une liste de dettes
 * connues. Le probleme n'est pas qu'elles existent, c'est qu'elles vivent
 * ailleurs que devant les yeux de celui qui decide d'ouvrir le robinet a
 * trafic : dans une conversation, un compte rendu, la memoire du developpeur.
 * Elles sont donc oubliees exactement au moment ou elles coutent cher.
 *
 * Deux natures de reserves, traitees differemment :
 *
 * - **Controles automatiques** — verifiables a l'execution : une offre active
 *   sans idv, un concours publie sans reglement, une page legale qui ne repond
 *   pas, un texte anglais qui n'est pas celui qu'on croit. Ils se ferment tout
 *   seuls quand la realite change. C'est la partie qui ne ment jamais.
 * - **Points declares** (ReadinessCatalog) — ce que le code ne peut pas
 *   constater : un reglement jamais relu par un juriste, un lot du plan non
 *   developpe, une decision en attente. Ils ne se ferment que par une decision
 *   humaine, tracee.
 *
 * Une decision peut faire taire le bandeau, jamais le constat : un controle
 * automatique encore au rouge reste affiche au rouge, avec ce qu'il a vu, meme
 * si quelqu'un l'a marque traite. Sinon on aurait construit une machine a
 * masquer les problemes plutot qu'a les montrer.
 */
final class ReadinessService
{
    /**
     * Duree de validite des controles automatiques.
     *
     * Ils interrogent la base et le service de contenus juridiques : les
     * rejouer a chaque page du back-office ferait payer cette latence a chaque
     * clic. Dix minutes, pour une reevaluation par `readiness:check` toutes les
     * cinq (config/cron) : le cache est ainsi toujours rafraichi avant
     * d'expirer, et l'exploitant ne paie jamais le recalcul. Un TTL plus court
     * que la periode du cron le ferait payer une ouverture d'ecran sur deux,
     * POST de formulaire compris — le middleware est pose sur tout /admin.
     * Le bouton « Reevaluer » force le calcul quand on vient de corriger.
     *
     * Les decisions, elles, sont toujours lues en direct : marquer un point
     * traite doit se voir immediatement.
     */
    private const CACHE_TTL = 600;

    /**
     * Valeur de dotation au-dela de laquelle NY et FL imposent un
     * enregistrement prealable et un cautionnement.
     */
    private const REGISTRATION_THRESHOLD_USD = 5000.0;

    public function __construct(
        private Config $config,
        private SettingRepository $settings,
        private LegalContentService $legal,
        private ReadinessRepository $readiness,
        private DrawingRepository $drawings,
        private string $cacheFile,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Etat complet, pret a afficher.
     *
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array{blocking:int, blocker:int, warning:int, info:int, settled:int, total:int},
     *     checked_at: string
     * }
     */
    public function report(bool $fresh = false): array
    {
        $checks = $this->checks($fresh);
        $decisions = $this->readiness->all();

        $items = [];
        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            $result = $checks['results'][$key] ?? null;

            // Un point declare est ouvert par nature : rien ne peut le fermer
            // sauf une decision. Un controle automatique porte le verdict de sa
            // derniere execution.
            $open = $result === null ? true : (bool) $result['open'];
            $ack = $decisions[$key] ?? null;

            $items[] = $definition + [
                'auto' => $result !== null,
                'open' => $open,
                'detail' => $result === null ? '' : (string) $result['detail'],
                'ack' => $ack,
                // Ce qui compte dans le bandeau : ouvert ET non arbitre.
                'blocking' => $open && $ack === null,
                // Le cas qui merite d'etre montre : quelqu'un a signe, mais le
                // controle voit toujours le probleme. Reserve aux controles
                // automatiques — un point declare est ouvert par construction
                // jusqu'a ce qu'on le ferme, le fermer ne contredit personne.
                'contradicted' => $result !== null && $open && $ack !== null,
            ];
        }

        // Ce qui bloque d'abord, puis par gravite. A gravite egale, l'ordre de
        // la cle : stable d'un affichage a l'autre, donc l'oeil retrouve une
        // ligne au meme endroit.
        $severities = array_keys(ReadinessCatalog::SEVERITIES);
        $rank = static function (array $item) use ($severities): int {
            $level = array_search($item['severity'], $severities, true);
            return ($item['blocking'] ? 0 : 100) + (is_int($level) ? $level : count($severities));
        };
        usort($items, static function (array $a, array $b) use ($rank): int {
            return [$rank($a), (string) $a['key']] <=> [$rank($b), (string) $b['key']];
        });

        return [
            'items' => $items,
            'counts' => $this->counts($items),
            'checked_at' => (string) $checks['checked_at'],
        ];
    }

    /**
     * Compteurs du bandeau, sans recalculer les controles.
     *
     * @return array{blocking:int, blocker:int, warning:int, info:int, settled:int, total:int}
     */
    public function summary(): array
    {
        return $this->report()['counts'];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{blocking:int, blocker:int, warning:int, info:int, settled:int, total:int}
     */
    private function counts(array $items): array
    {
        $counts = ['blocking' => 0, 'blocker' => 0, 'warning' => 0, 'info' => 0, 'settled' => 0, 'total' => 0];
        foreach ($items as $item) {
            $counts['total']++;
            $severity = (string) $item['severity'];
            if ($item['blocking']) {
                $counts['blocking']++;
                if (array_key_exists($severity, $counts)) {
                    $counts[$severity]++;
                }
            } else {
                $counts['settled']++;
            }
        }
        return $counts;
    }

    /**
     * Catalogue complet : controles automatiques puis points declares.
     *
     * @return list<array{key:string, severity:string, area:string, title:string,
     *                    why:string, action:string, owner:string, link:string}>
     */
    private function definitions(): array
    {
        return array_merge(self::CHECKS, ReadinessCatalog::DECLARED);
    }

    /**
     * Resultats des controles automatiques, mis en cache.
     *
     * @return array{results: array<string,array{open:bool, detail:string}>, checked_at:string}
     */
    private function checks(bool $fresh = false): array
    {
        if (!$fresh && is_file($this->cacheFile) && (time() - (int) filemtime($this->cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) @file_get_contents($this->cacheFile), true);
            // `checked_at` autant que `results` : un fichier tronque porterait
            // l'un sans l'autre, et l'ecran planterait au rendu de la date.
            if (
                is_array($cached)
                && isset($cached['results'], $cached['checked_at'])
                && is_array($cached['results'])
            ) {
                /** @var array{results: array<string,array{open:bool, detail:string}>, checked_at:string} $cached */
                return $cached;
            }
        }

        $payload = [
            'results' => $this->runChecks(),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
        $this->store($payload);

        return $payload;
    }

    /** @param array{results:array<string,array{open:bool,detail:string}>, checked_at:string} $payload */
    private function store(array $payload): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->warning('Reserves d\'ouverture : dossier de cache impossible a creer', [
                'dir' => $dir,
            ]);
            return;
        }
        // json_encode() renvoie false sur une chaine mal encodee — un nom
        // d'offre saisi en latin-1, par exemple. file_put_contents($tmp, false)
        // ecrirait alors un fichier VIDE en renvoyant 0, et le rename le
        // promouvrait en cache : l'ecran se viderait de ses verdicts sans rien
        // signaler.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->logger->error('Reserves d\'ouverture : verdicts non serialisables', [
                'error' => json_last_error_msg(),
            ]);
            return;
        }

        // Ecriture atomique : deux onglets du back-office peuvent recalculer en
        // meme temps, aucun ne doit lire un fichier a moitie ecrit.
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            // Sans cette trace, le symptome — un recalcul complet a chaque page
            // du back-office — ne se relie a rien.
            $this->logger->warning('Reserves d\'ouverture : cache non ecrit', ['file' => $tmp]);
            return;
        }
        if (!@rename($tmp, $this->cacheFile)) {
            $this->logger->warning('Reserves d\'ouverture : cache non promu', ['file' => $this->cacheFile]);
            @unlink($tmp);
        }
    }

    // ------------------------------------------------------------------ Controles

    /**
     * Metadonnees des controles automatiques.
     *
     * Elles sont ici et les verdicts dans runChecks() : le libelle d'un point
     * ne doit pas dependre de ce que le controle a trouve, sinon l'ecran change
     * de discours d'une execution a l'autre.
     *
     * @var list<array{key:string, severity:string, area:string, title:string,
     *                 why:string, action:string, owner:string, link:string}>
     */
    private const CHECKS = [
        [
            'key' => 'legal.terms_content',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Légal',
            'title' => 'Les CGU anglaises ne sont pas des CGU',
            'why' => 'La case de consentement obligatoire renvoie aux « Terms of Service ». Le document '
                . 'servi sous ce nom par le service de contenus est en réalité une politique de protection '
                . 'des données. Le participant accepte un document qui n\'existe pas.',
            'action' => 'Faire écrire de vraies conditions générales anglaises dans le dépôt legals, ou '
                . 'retirer la mention du texte de consentement.',
            'owner' => 'Dépôt legals',
            'link' => '/admin/settings',
        ],
        [
            'key' => 'legal.privacy_ccpa',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Légal',
            'title' => 'Aucune mention CCPA/CPRA dans la politique de confidentialité',
            'why' => 'Le document servi en anglais est une politique RGPD traduite. Un résident californien '
                . 'n\'y trouve ni ses droits, ni la description des catégories de données vendues ou '
                . 'partagées — alors que le site affiche un lien « Do Not Sell ».',
            'action' => 'Ajouter une section California Privacy Rights au document anglais du dépôt legals.',
            'owner' => 'Dépôt legals',
            'link' => '/admin/settings',
        ],
        [
            'key' => 'legal.partners_list',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Légal',
            'title' => 'La liste des partenaires ne correspond à aucun annonceur du site',
            'why' => 'La page « Marketing Partners » est censée nommer les destinataires réels des données. '
                . 'Aucun des annonceurs des offres actives n\'y figure : elle décrit un autre site.',
            'action' => 'Faire établir la liste des annonceurs américains dans le dépôt legals, ou retirer '
                . 'la page du pied de page en attendant.',
            'owner' => 'Dépôt legals',
            'link' => '/admin/settings',
        ],
        [
            'key' => 'legal.pages_unavailable',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Légal',
            'title' => 'Une page légale affichée ne rend aucun contenu',
            'why' => 'Un lien de pied de page qui ne mène à rien vaut moins que pas de lien : il donne '
                . 'l\'apparence d\'une information légale sans la fournir.',
            'action' => 'Retirer la page du pied de page, ou la faire écrire dans la langue du site.',
            'owner' => 'Dépôt legals',
            'link' => '/admin/settings',
        ],
        [
            'key' => 'legal.cited_not_linked',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Légal',
            'title' => 'Un document cité dans le consentement n\'est pas atteignable',
            'why' => 'Le texte de la case nomme un document que le pied de page n\'affiche pas : le '
                . 'participant a accepté quelque chose qu\'il ne pouvait pas lire.',
            'action' => 'Remettre le document en pied de page, ou retirer sa mention du texte de '
                . 'consentement.',
            'owner' => 'Exploitation',
            'link' => '/admin/settings',
        ],
        [
            'key' => 'legal.amoe_address',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Légal',
            'title' => 'Adresse postale hors des États-Unis',
            'why' => 'CAN-SPAM impose une adresse postale physique, et la méthode alternative d\'entrée '
                . '(AMOE) suppose qu\'un participant américain puisse poster une carte. Une adresse '
                . 'française allonge le trajet de deux à trois semaines et fait douter de la praticabilité '
                . 'de l\'AMOE.',
            'action' => 'Obtenir une adresse de réception aux États-Unis, ou allonger les délais du '
                . 'règlement en conséquence.',
            'owner' => 'Exploitation',
            'link' => '/admin/settings',
        ],
        [
            'key' => 'sweepstakes.rules_missing',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Concours',
            'title' => 'Concours publié sans Official Rules',
            'why' => 'Un jeu-concours américain sans règlement publié est une non-conformité ouverte, '
                . 'opposable dès la première participation.',
            'action' => 'Rédiger le règlement, ou repasser le concours en brouillon.',
            'owner' => 'Exploitation',
            'link' => '/admin/sweepstakes',
        ],
        [
            'key' => 'sweepstakes.rules_thin',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Concours',
            'title' => 'Official Rules anormalement courtes',
            'why' => 'Un règlement complet — no purchase necessary, AMOE, éligibilité, probabilités, '
                . 'valeur du lot, sélection, notification, liste des gagnants — ne tient pas en quelques '
                . 'lignes. Un texte trop court signale une troncature.',
            'action' => 'Ouvrir le concours et vérifier que le règlement n\'a pas été tronqué à la saisie.',
            'owner' => 'Exploitation',
            'link' => '/admin/sweepstakes',
        ],
        [
            'key' => 'sweepstakes.rules_mentions',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Concours',
            'title' => 'Mention obligatoire absente d\'un règlement publié',
            'why' => 'NO PURCHASE NECESSARY, la méthode alternative d\'entrée et les probabilités de gain '
                . 'sont les trois mentions qu\'un attorney general cherche en premier. Leur absence '
                . 'requalifie le jeu-concours en loterie privée.',
            'action' => 'Compléter le règlement du concours nommé, puis l\'enregistrer depuis sa fiche.',
            'owner' => 'Juridique',
            'link' => '/admin/sweepstakes',
        ],
        [
            'key' => 'sweepstakes.excluded_inert',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Concours',
            'title' => 'Exclusion d\'État sans effet',
            'why' => 'Le formulaire ne propose que les cinquante États et le District of Columbia. Exclure '
                . 'un territoire qui n\'y figure pas ne refuse personne : le règlement annonce une '
                . 'restriction que le site n\'applique pas.',
            'action' => 'Retirer le code du champ des États exclus, ou ouvrir les territoires à la saisie.',
            'owner' => 'Exploitation',
            'link' => '/admin/sweepstakes',
        ],
        [
            'key' => 'sweepstakes.registration_threshold',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Concours',
            'title' => 'Dotation au-dessus du seuil d\'enregistrement NY/FL',
            'why' => 'New York et la Floride imposent un enregistrement préalable et un cautionnement dès '
                . 'que la valeur annoncée des lots dépasse 5 000 $. Ouvrir sans cela expose à une sanction '
                . 'de l\'État concerné.',
            'action' => 'Enregistrer le concours dans ces deux États, ou les exclure, ou baisser la valeur '
                . 'annoncée.',
            'owner' => 'Juridique',
            'link' => '/admin/sweepstakes',
        ],
        [
            'key' => 'sweepstakes.drawing_pending',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Tirages',
            'title' => 'Concours terminé sans finaliste tiré',
            'why' => 'Le règlement annonce une date de tirage. Un concours clos dont le finaliste n\'est '
                . 'pas tiré laisse les participants sans réponse et décale le tirage annuel.',
            'action' => 'Lancer le tirage du concours depuis l\'écran des tirages.',
            'owner' => 'Exploitation',
            'link' => '/admin/drawings',
        ],
        [
            'key' => 'offers.idv_missing',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Offres',
            'title' => 'Offre active sans idv',
            'why' => 'Sans idv, l\'offre n\'est jamais affichée : elle occupe une place dans le parcours '
                . 'sans rien rapporter, et son absence ne se voit dans aucune statistique.',
            'action' => 'Renseigner l\'identifiant de création fourni par la régie, ou désactiver l\'offre.',
            'owner' => 'Exploitation',
            'link' => '/admin/offers',
        ],
        [
            'key' => 'offers.idc_missing',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Offres',
            'title' => 'Offre active sans idc',
            'why' => 'L\'idc est la clé de rapprochement des revenus. Sans lui, l\'offre s\'affiche et se '
                . 'clique, mais son chiffre d\'affaires n\'est rattaché à rien : son eCPM reste à zéro et '
                . 'l\'arbitrage la relègue à tort.',
            'action' => 'Renseigner l\'identifiant de campagne fourni par la régie.',
            'owner' => 'Exploitation',
            'link' => '/admin/offers',
        ],
        [
            'key' => 'config.affiliate_ids',
            'severity' => ReadinessCatalog::BLOCKER,
            'area' => 'Configuration',
            'title' => 'Identifiant de site de la régie non renseigné',
            'why' => 'Le paramètre « ids » identifie le site auprès de la régie. Vide, tous les clics '
                . 'sortent sans propriétaire : le trafic est envoyé et jamais payé.',
            'action' => 'Renseigner AFFILIATE_SITE_IDS dans le .env du serveur.',
            'owner' => 'Développement',
            'link' => '',
        ],
        [
            'key' => 'config.affiliate_report',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Configuration',
            'title' => 'Flux de revenus de la régie non configuré',
            'why' => 'Sans identifiants de reporting, la tâche horaire ne rapatrie aucun revenu. Les eCPM '
                . 'restent à zéro, donc l\'ordre d\'affichage des offres n\'est plus arbitré par le revenu '
                . 'mais par le poids saisi à la main.',
            'action' => 'Renseigner AFFILIATE_REPORT_LOGIN et AFFILIATE_REPORT_PASSWORD dans le .env.',
            'owner' => 'Développement',
            'link' => '/admin/stats/offers',
        ],
        [
            'key' => 'config.lead_verify',
            'severity' => ReadinessCatalog::WARNING,
            'area' => 'Configuration',
            'title' => 'Vérification e-mail et téléphone non branchée',
            'why' => 'Aucun fournisseur n\'est choisi : les colonnes de vérification restent à zéro. Le '
                . 'téléphone étant le nerf de la rémunération, un numéro non vérifié se paie en rejets chez '
                . 'l\'acheteur.',
            'action' => 'Choisir un fournisseur de vérification américain et renseigner '
                . 'LEAD_VERIFY_PROVIDER.',
            'owner' => 'Décision',
            'link' => '',
        ],
    ];

    /**
     * Execute tous les controles.
     *
     * Chaque controle est isole : une base momentanement indisponible ou un
     * service de contenus muet ne doit pas priver l'exploitant des quinze
     * autres verdicts. Un controle qui echoue se declare ouvert et le dit.
     *
     * @return array<string,array{open:bool, detail:string}>
     */
    private function runChecks(): array
    {
        $results = [];
        foreach ($this->checkers() as $key => $check) {
            try {
                $results[$key] = $check();
            } catch (\Throwable $e) {
                $results[$key] = [
                    'open' => true,
                    'detail' => 'Contrôle impossible : ' . $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /**
     * Execute UN controle, nomme, sans passer par le cache.
     *
     * Sert aux tests : les controles qui interrogent la base se prouvent sur
     * des donnees fabriquees dans une transaction annulee, sans avoir a joindre
     * le service de contenus juridiques. C'est la seule facon de verifier un
     * verdict sans dependre du reseau.
     *
     * @return array{open:bool, detail:string}
     */
    public function run(string $key): array
    {
        $checkers = $this->checkers();
        if (!isset($checkers[$key])) {
            throw new \InvalidArgumentException('Contrôle inconnu : ' . $key);
        }
        return ($checkers[$key])();
    }

    /**
     * Registre des controles : cle => execution.
     *
     * Les cles doivent correspondre exactement a celles de self::CHECKS. Une
     * divergence ne provoque aucune erreur — le point apparait simplement comme
     * « declare », donc ouvert pour toujours, et son controle ne s'execute
     * jamais. ReadinessCatalogTest verifie l'appariement.
     *
     * @return array<string, callable(): array{open:bool, detail:string}>
     */
    private function checkers(): array
    {
        return [
            'legal.terms_content' => fn(): array => $this->checkTermsContent(),
            'legal.privacy_ccpa' => fn(): array => $this->checkPrivacyCcpa(),
            'legal.partners_list' => fn(): array => $this->checkPartnersList(),
            'legal.pages_unavailable' => fn(): array => $this->checkLegalPages(),
            'legal.cited_not_linked' => fn(): array => $this->checkCitedDocuments(),
            'legal.amoe_address' => fn(): array => $this->checkPostalAddresses(),
            'sweepstakes.rules_missing' => fn(): array => $this->checkRulesMissing(),
            'sweepstakes.rules_thin' => fn(): array => $this->checkRulesThin(),
            'sweepstakes.rules_mentions' => fn(): array => $this->checkRulesMentions(),
            'sweepstakes.excluded_inert' => fn(): array => $this->checkExcludedStates(),
            'sweepstakes.registration_threshold' => fn(): array => $this->checkRegistrationThreshold(),
            'sweepstakes.drawing_pending' => fn(): array => $this->checkPendingDrawings(),
            'offers.idv_missing' => fn(): array => $this->checkOfferIdentifier('offer_platform_idv', 'idv'),
            'offers.idc_missing' => fn(): array => $this->checkOfferIdentifier('offer_platform_idc', 'idc'),
            'config.affiliate_ids' => fn(): array => $this->checkConfigValue(['AFFILIATE_SITE_IDS']),
            'config.affiliate_report' => fn(): array => $this->checkConfigValue(
                ['AFFILIATE_REPORT_LOGIN', 'AFFILIATE_REPORT_PASSWORD']
            ),
            'config.lead_verify' => fn(): array => $this->checkConfigValue(['LEAD_VERIFY_PROVIDER']),
        ];
    }

    /** @return array{open:bool, detail:string} */
    private function checkTermsContent(): array
    {
        $fragment = $this->legal->fragment('terms');
        if ($fragment === null) {
            return ['open' => true, 'detail' => 'Le document « Terms of Service » ne rend aucun contenu.'];
        }

        $signature = ReadinessProbes::looksLikePrivacyPolicy($fragment);
        if ($signature !== null) {
            return [
                'open' => true,
                'detail' => sprintf('Le document contient « %s » : c\'est une politique de données.', $signature),
            ];
        }
        return ['open' => false, 'detail' => 'Le document ne porte pas la signature d\'une politique de données.'];
    }

    /** @return array{open:bool, detail:string} */
    private function checkPrivacyCcpa(): array
    {
        $fragment = $this->legal->fragment('privacy');
        if ($fragment === null) {
            return ['open' => true, 'detail' => 'La politique de confidentialité ne rend aucun contenu.'];
        }

        $marker = ReadinessProbes::mentionsCaliforniaRights($fragment);
        if ($marker !== null) {
            return ['open' => false, 'detail' => sprintf('Mention « %s » trouvée.', $marker)];
        }
        return [
            'open' => true,
            'detail' => 'Aucune mention CCPA, CPRA ou California Privacy Rights dans le texte servi en anglais.',
        ];
    }

    /**
     * La page « Marketing Partners » nomme-t-elle TOUS les annonceurs diffuses ?
     *
     * « Au moins un » ne suffit pas : la page est censee lister les
     * destinataires reels des donnees personnelles. Neuf annonceurs absents sur
     * dix, c'est neuf destinataires non declares — et un point bloquant qui
     * s'afficherait au vert parce qu'un seul nom coincide.
     *
     * @return array{open:bool, detail:string}
     */
    private function checkPartnersList(): array
    {
        $advertisers = $this->readiness->activeAdvertisers();
        if ($advertisers === []) {
            return ['open' => false, 'detail' => 'Aucune offre active : rien à comparer pour l\'instant.'];
        }

        $fragment = $this->legal->fragment('partners');
        if ($fragment === null) {
            return ['open' => true, 'detail' => 'La page des partenaires ne rend aucun contenu.'];
        }

        $found = ReadinessProbes::names($fragment, $advertisers);
        $missing = array_values(array_diff($advertisers, $found));

        if ($missing === []) {
            return [
                'open' => false,
                'detail' => sprintf('Les %d annonceurs diffusés sont cités dans la page.', count($advertisers)),
            ];
        }

        return [
            'open' => true,
            'detail' => sprintf(
                '%d annonceur(s) diffusé(s) sur %d absent(s) de la page : %s.',
                count($missing),
                count($advertisers),
                implode(', ', $missing)
            ),
        ];
    }

    /** @return array{open:bool, detail:string} */
    private function checkLegalPages(): array
    {
        $linked = $this->settings->legalLinks();
        // Zero page legale affichee n'est pas « rien a verifier » : c'est la
        // pire configuration possible. La declarer verte sur un point bloquant
        // reviendrait a recompenser le fait d'avoir tout retire.
        if ($linked === []) {
            return [
                'open' => true,
                'detail' => 'Aucune page légale n\'est affichée en pied de page.',
            ];
        }

        // Seules les pages REELLEMENT affichees sont interrogees. Tester toute
        // la couverture du service de contenus ferait payer, a chaque
        // evaluation, l'echec de pages qu'on n'affiche pas — `cookies` n'existe
        // pas en anglais et n'est jamais mise en cache, donc son delai est du a
        // chaque fois.
        $broken = [];
        foreach (array_keys($linked) as $page) {
            if ($this->legal->fragment($page) === null) {
                $broken[] = $page;
            }
        }

        if ($broken === []) {
            return [
                'open' => false,
                'detail' => sprintf('%d page(s) affichée(s), toutes servies.', count($linked)),
            ];
        }
        return ['open' => true, 'detail' => 'Sans contenu : ' . implode(', ', $broken) . '.'];
    }

    /** @return array{open:bool, detail:string} */
    private function checkCitedDocuments(): array
    {
        $linked = $this->settings->legalLinks();
        $missing = [];
        foreach (ReadinessCatalog::citedDocuments() as $label => $page) {
            if (!array_key_exists($page, $linked)) {
                $missing[] = $label;
            }
        }

        if ($missing === []) {
            return ['open' => false, 'detail' => 'Tous les documents cités sont affichés en pied de page.'];
        }
        return [
            'open' => true,
            'detail' => 'Cité(s) dans le consentement mais absent(s) du pied de page : '
                . implode(', ', $missing) . '.',
        ];
    }

    /**
     * Adresses postales : celle du site et celle de chaque concours publie.
     *
     * Une adresse VIDE est un defaut a part entiere, pas un cas a ignorer :
     * CAN-SPAM impose une adresse physique, et sans elle l'AMOE n'a pas de
     * destinataire. La passer sous silence produirait un vert affirmatif sur
     * un point non fait.
     *
     * @return array{open:bool, detail:string}
     */
    private function checkPostalAddresses(): array
    {
        $addresses = ['réglages du site' => (string) ($this->settings->all()['site_postal_address'] ?? '')];
        foreach ($this->readiness->publishedSweepstakes() as $sweepstake) {
            $addresses[$sweepstake['slug']] = $sweepstake['sponsor_address'];
        }

        $missing = [];
        $abroad = [];
        foreach ($addresses as $label => $address) {
            if (trim($address) === '') {
                $missing[] = $label;
            } elseif (!ReadinessProbes::looksLikeUsAddress($address)) {
                $abroad[] = $label;
            }
        }

        $problems = [];
        if ($missing !== []) {
            $problems[] = 'sans adresse : ' . implode(', ', $missing);
        }
        if ($abroad !== []) {
            $problems[] = 'hors États-Unis : ' . implode(', ', $abroad);
        }

        if ($problems === []) {
            return ['open' => false, 'detail' => 'Toutes les adresses ont la forme d\'une adresse américaine.'];
        }
        return ['open' => true, 'detail' => ucfirst(implode(' ; ', $problems)) . '.'];
    }

    /** @return array{open:bool, detail:string} */
    private function checkRulesMissing(): array
    {
        // Sur le TEXTE debalise, comme la validation a la publication : un
        // reglement reduit a « <p><br></p> » n'est pas vide au sens SQL et
        // serait pourtant refuse a la publication.
        $slugs = [];
        foreach ($this->readiness->publishedSweepstakes() as $sweepstake) {
            if (OfficialRules::isEmpty($sweepstake['rules'])) {
                $slugs[] = $sweepstake['slug'];
            }
        }

        if ($slugs === []) {
            return ['open' => false, 'detail' => 'Tous les concours publiés ont un règlement.'];
        }
        return ['open' => true, 'detail' => 'Sans règlement : ' . implode(', ', $slugs) . '.'];
    }

    /**
     * Seuil et mesure de OfficialRules, partages avec la validation a la
     * publication : deux seuils qui divergent donneraient un concours refuse au
     * formulaire et pourtant declare bon ici.
     *
     * @return array{open:bool, detail:string}
     */
    private function checkRulesThin(): array
    {
        $thin = [];
        foreach ($this->readiness->publishedSweepstakes() as $sweepstake) {
            if (OfficialRules::isTooShort($sweepstake['rules'])) {
                $thin[] = sprintf(
                    '%s (%d car.)',
                    $sweepstake['slug'],
                    OfficialRules::length($sweepstake['rules'])
                );
            }
        }

        if ($thin === []) {
            return ['open' => false, 'detail' => 'Aucun règlement tronqué.'];
        }
        return ['open' => true, 'detail' => 'Règlement anormalement court — ' . implode(', ', $thin) . '.'];
    }

    /**
     * Mentions obligatoires des concours DEJA publies.
     *
     * Le formulaire de publication les exige, mais un concours mis en ligne
     * autrement — un seed, un SQL direct, une publication anterieure a ce
     * controle — n'est jamais repasse devant lui. Sans ce controle, un
     * reglement de 1 600 caracteres sans NO PURCHASE NECESSARY ressortait vert
     * partout, alors que sa republication serait refusee.
     *
     * @return array{open:bool, detail:string}
     */
    private function checkRulesMentions(): array
    {
        $incomplets = [];
        foreach ($this->readiness->publishedSweepstakes() as $sweepstake) {
            if (OfficialRules::isEmpty($sweepstake['rules'])) {
                continue; // Deja porte par sweepstakes.rules_missing.
            }
            $missing = OfficialRules::missingMentions($sweepstake['rules']);
            if ($missing !== []) {
                $incomplets[] = sprintf('%s (%s)', $sweepstake['slug'], implode(', ', $missing));
            }
        }

        if ($incomplets === []) {
            return ['open' => false, 'detail' => 'Toutes les mentions obligatoires sont présentes.'];
        }
        return ['open' => true, 'detail' => 'Mention(s) manquante(s) — ' . implode(' ; ', $incomplets) . '.'];
    }

    /** @return array{open:bool, detail:string} */
    private function checkRegistrationThreshold(): array
    {
        $exposed = [];
        foreach ($this->readiness->publishedSweepstakes() as $sweepstake) {
            // « Depasse » 5 000 $, pas « atteint » : une dotation a exactement
            // 5 000 $ n'est pas soumise a l'enregistrement, et un faux rouge sur
            // une valeur ronde — la plus frequente — decredibiliserait l'ecran.
            if ($sweepstake['prize'] <= self::REGISTRATION_THRESHOLD_USD) {
                continue;
            }

            // MEME decoupage que le refus reel du participant
            // (LeadValidator via UsStates::parseExcluded). Un decoupage plus
            // permissif ici — sur les espaces, par exemple — lirait « NY FL »
            // comme deux Etats exclus et se tairait, alors que le formulaire,
            // lui, laisse entrer les residents de New York.
            $excluded = UsStates::parseExcluded($sweepstake['excluded']);
            $states = array_values(array_diff(['NY', 'FL'], $excluded));
            if ($states !== []) {
                $exposed[] = sprintf('%s (%s)', $sweepstake['slug'], implode(' et ', $states));
            }
        }

        if ($exposed === []) {
            return ['open' => false, 'detail' => 'Aucune dotation publiée au-dessus de 5 000 $ sans exclusion.'];
        }
        return ['open' => true, 'detail' => 'À enregistrer : ' . implode(', ', $exposed) . '.'];
    }

    /** @return array{open:bool, detail:string} */
    private function checkExcludedStates(): array
    {
        $inert = [];
        foreach ($this->readiness->publishedSweepstakes() as $sweepstake) {
            foreach (UsStates::parseExcluded($sweepstake['excluded']) as $code) {
                if (!isset(UsStates::STATES[$code])) {
                    $inert[] = sprintf('%s : %s', $sweepstake['slug'], $code);
                }
            }
        }

        if ($inert === []) {
            return ['open' => false, 'detail' => 'Tous les États exclus sont proposés au formulaire.'];
        }
        return ['open' => true, 'detail' => 'Exclusion sans effet — ' . implode(', ', $inert) . '.'];
    }

    /**
     * Concours en attente de tirage — lus dans le domaine Tirages.
     *
     * La requete n'est pas reecrite ici : DrawingRepository fait deja foi pour
     * l'ecran des tirages et pour `drawing:run --pending`. Deux requetes du
     * meme nom aux criteres differents feraient reclamer un tirage par un ecran
     * et pas par l'autre.
     *
     * @return array{open:bool, detail:string}
     */
    private function checkPendingDrawings(): array
    {
        $slugs = array_map(
            static fn(array $row): string => (string) $row['sweepstake_slug'],
            $this->drawings->sweepstakesAwaitingDrawing()
        );
        if ($slugs === []) {
            return ['open' => false, 'detail' => 'Aucun concours terminé en attente de tirage.'];
        }
        return ['open' => true, 'detail' => 'En attente : ' . implode(', ', $slugs) . '.'];
    }

    /** @return array{open:bool, detail:string} */
    private function checkOfferIdentifier(string $column, string $label): array
    {
        $offers = $this->readiness->activeOffersMissing($column);
        if ($offers === []) {
            return ['open' => false, 'detail' => sprintf('Toutes les offres actives portent leur %s.', $label)];
        }
        return ['open' => true, 'detail' => sprintf('Sans %s : %s.', $label, implode(', ', $offers))];
    }

    /**
     * @param list<string> $keys
     * @return array{open:bool, detail:string}
     */
    private function checkConfigValue(array $keys): array
    {
        $empty = [];
        foreach ($keys as $key) {
            if (trim((string) $this->config->get($key, '')) === '') {
                $empty[] = $key;
            }
        }

        if ($empty === []) {
            return ['open' => false, 'detail' => 'Renseigné dans le .env du serveur.'];
        }
        return ['open' => true, 'detail' => 'Vide : ' . implode(', ', $empty) . '.'];
    }
}
