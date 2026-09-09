<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Config;
use App\Core\Database;
use App\Modules\Admin\Models\Repositories\ReadinessRepository;
use App\Modules\Admin\Models\Repositories\SettingRepository;
use App\Modules\Admin\Services\ReadinessService;
use App\Modules\Drawings\Models\Repositories\DrawingRepository;
use App\Modules\Legal\Services\LegalContentService;
use App\Modules\Sweepstakes\Services\OfficialRules;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Les controles d'ouverture qui interrogent la base, sur de vraies donnees.
 *
 * Ces controles ont un pouvoir asymetrique : au rouge ils font perdre du temps,
 * au vert ils font renoncer a regarder. Un faux vert est donc le seul defaut
 * qui compte vraiment — et il ne se voit pas, puisqu'un ecran silencieux
 * ressemble a un site conforme.
 *
 * Deux faux verts ont existe, tous deux invisibles a la lecture :
 *
 * 1. la requete filtrait sur `sweepstake_status IN ("published","closed")`
 *    alors que l'ENUM vaut ('draft','published','paused','ended'). MariaDB ne
 *    signale pas une valeur absente d'un ENUM : elle ne matche jamais. Le
 *    controle des tirages en attente ne voyait donc AUCUN concours termine ;
 * 2. le decoupage des Etats exclus se faisait sur les espaces autant que sur
 *    les virgules, quand le refus reel du participant n'accepte que la virgule.
 *    « NY FL » se lisait ici comme deux Etats exclus — et le bloquant sur le
 *    seuil d'enregistrement NY/FL se taisait — alors que le formulaire, lui,
 *    laissait entrer les residents de New York.
 *
 * Chaque scenario est fabrique puis annule (ROLLBACK) : la base de
 * developpement en ressort inchangee.
 */
final class ReadinessChecksTest extends TestCase
{
    private Connection $connection;
    private ReadinessService $readiness;

    protected function setUp(): void
    {
        $config = new Config($_ENV);
        $database = new Database($config);

        try {
            $this->connection = $database->connection();
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Base indisponible : ' . $e->getMessage());
        }

        $this->readiness = new ReadinessService(
            $config,
            new SettingRepository($database),
            new LegalContentService($config, new Client(), new NullLogger(), sys_get_temp_dir()),
            new ReadinessRepository($database),
            new DrawingRepository($database),
            sys_get_temp_dir() . '/readiness-test-' . getmypid() . '.json',
            new NullLogger(),
        );

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    /**
     * Un concours termine dont le finaliste n'est pas tire doit etre nomme.
     *
     * C'est le scenario que le statut inexistant rendait indetectable.
     */
    public function testUnConcoursTermineSansTirageEstSignale(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'ended',
            'sweepstake_date_end' => date('Y-m-d', strtotime('-10 days')),
        ]);

        $verdict = $this->readiness->run('sweepstakes.drawing_pending');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    public function testUnConcoursEnCoursNEstPasReclameAuTirage(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_date_end' => date('Y-m-d', strtotime('+30 days')),
        ]);

        self::assertStringNotContainsString(
            $slug,
            $this->readiness->run('sweepstakes.drawing_pending')['detail']
        );
    }

    /**
     * Le seuil NY/FL doit mordre des que l'exclusion n'est pas REELLEMENT
     * appliquee — et « NY FL » sans virgule ne l'est pas.
     */
    public function testUneExclusionMalSaisieNeDesarmePasLeSeuilNyFl(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_prize_value_usd' => 6000,
            'sweepstake_excluded_states' => 'NY FL',
        ]);

        $verdict = $this->readiness->run('sweepstakes.registration_threshold');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    public function testUneExclusionCorrectementSaisieDesarmeLeSeuilNyFl(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_prize_value_usd' => 6000,
            'sweepstake_excluded_states' => 'NY,FL',
        ]);

        self::assertStringNotContainsString(
            $slug,
            $this->readiness->run('sweepstakes.registration_threshold')['detail']
        );
    }

    /** Une exclusion que le formulaire n'applique pas doit etre denoncee. */
    public function testUneExclusionSansEffetEstDenoncee(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_excluded_states' => 'RI,PR',
        ]);

        $verdict = $this->readiness->run('sweepstakes.excluded_inert');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug . ' : PR', $verdict['detail']);
    }

    public function testUneExclusionMalSaisieEstDenonceeAussi(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_excluded_states' => 'NY FL',
        ]);

        self::assertStringContainsString(
            $slug . ' : NY FL',
            $this->readiness->run('sweepstakes.excluded_inert')['detail']
        );
    }

    /**
     * Le seuil porte sur le TEXTE, pas sur le balisage.
     *
     * Le reglement fabrique ici depasse largement 1 500 caracteres de HTML et
     * reste sous 1 500 caracteres de texte : mesure sur le HTML, il passait au
     * vert alors que le formulaire refuse de le publier.
     */
    public function testUnReglementTronqueMaisLourdementBaliseEstSignale(): void
    {
        $texte = str_repeat('Official rules text. ', 60);          // ~1 260 caracteres
        $html = '<p><strong><em>' . $texte . '</em></strong></p>'
            . str_repeat('<div class="section-of-the-official-rules"></div>', 30);

        self::assertLessThan(1500, mb_strlen(trim(strip_tags($html))));
        self::assertGreaterThan(1500, mb_strlen($html));

        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_official_rules_html' => $html,
        ]);

        $verdict = $this->readiness->run('sweepstakes.rules_thin');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    public function testUnConcoursPublieSansReglementEstSignale(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_official_rules_html' => '',
        ]);

        $verdict = $this->readiness->run('sweepstakes.rules_missing');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    public function testUneOffreActiveSansIdvEstSignalee(): void
    {
        $nom = $this->creerOffre(['offer_active' => 1, 'offer_platform_idv' => '']);

        $verdict = $this->readiness->run('offers.idv_missing');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($nom, $verdict['detail']);
    }

    /** Une offre desactivee n'est pas diffusee : elle n'a rien a signaler. */
    public function testUneOffreInactiveSansIdvNEstPasSignalee(): void
    {
        $nom = $this->creerOffre(['offer_active' => 0, 'offer_platform_idv' => '']);

        self::assertStringNotContainsString(
            $nom,
            $this->readiness->run('offers.idv_missing')['detail']
        );
    }

    /**
     * Une adresse de sponsor hors des Etats-Unis rend l'AMOE impraticable.
     */
    public function testUneAdresseDeSponsorEtrangereEstSignalee(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_sponsor_address' => '15 rue des Cuirassiers, 69003 Lyon, France',
        ]);

        $verdict = $this->readiness->run('legal.amoe_address');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    /** Un concours mis en pause apres sa date de fin doit aussi son tirage. */
    public function testUnConcoursEnPauseApresSaDateDeFinEstReclameAuTirage(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'paused',
            'sweepstake_date_end' => date('Y-m-d', strtotime('-3 days')),
        ]);

        self::assertStringContainsString(
            $slug,
            $this->readiness->run('sweepstakes.drawing_pending')['detail']
        );
    }

    /**
     * Un brouillon dont la date est passee est reclame lui aussi.
     *
     * Le controle herite de DrawingRepository, qui ne filtre aucun statut :
     * c'est ce que montre deja /admin/drawings, et les deux ecrans doivent dire
     * la meme chose. Un concours repasse en brouillon apres avoir collecte doit
     * son tirage a ses participants.
     */
    public function testUnBrouillonDontLaDateEstPasseeEstReclameAuTirage(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'draft',
            'sweepstake_date_end' => date('Y-m-d', strtotime('-1 day')),
        ]);

        self::assertStringContainsString(
            $slug,
            $this->readiness->run('sweepstakes.drawing_pending')['detail']
        );
    }

    /**
     * NY et FL n'imposent l'enregistrement qu'AU-DESSUS de 5 000 $.
     *
     * Un faux rouge sur la valeur ronde — la plus frequente — abimerait la
     * credibilite de l'ecran pour rien.
     */
    public function testUneDotationDeCinqMilleDollarsPileNeDeclencheRien(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_prize_value_usd' => 5000,
            'sweepstake_excluded_states' => '',
        ]);

        self::assertStringNotContainsString(
            $slug,
            $this->readiness->run('sweepstakes.registration_threshold')['detail']
        );
    }

    /**
     * Un reglement reduit a du balisage vide n'est pas un reglement.
     *
     * `COALESCE(..., "") != ""` le laissait passer : la colonne n'est pas vide
     * au sens SQL, alors que le formulaire de publication le refuse.
     */
    public function testUnReglementReduitADuBalisageVideEstSignale(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_official_rules_html' => '<p><br></p><div></div>',
        ]);

        $verdict = $this->readiness->run('sweepstakes.rules_missing');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    /**
     * Un reglement assez long mais sans NO PURCHASE NECESSARY.
     *
     * Le formulaire de publication le refuse. Un concours mis en ligne
     * autrement — seed, SQL direct, publication anterieure a ce controle — ne
     * repasse jamais devant lui : c'est ce controle-ci qui doit le rattraper.
     */
    public function testUnReglementSansMentionObligatoireEstSignale(): void
    {
        $html = '<p>' . str_repeat('Official rules paragraph. ', 80)
            . 'Alternate method of entry is described below. Odds of winning depend on entries.</p>';

        self::assertGreaterThan(OfficialRules::MIN_LENGTH, OfficialRules::length($html));

        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_official_rules_html' => $html,
        ]);

        $verdict = $this->readiness->run('sweepstakes.rules_mentions');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
        self::assertStringContainsString('NO PURCHASE NECESSARY', $verdict['detail']);
    }

    public function testUnReglementCompletNEstPasSignale(): void
    {
        $html = '<p>' . str_repeat('Official rules paragraph. ', 80)
            . 'NO PURCHASE NECESSARY. Alternate method of entry is described below. '
            . 'Odds of winning depend on the number of entries.</p>';

        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_official_rules_html' => $html,
        ]);

        self::assertStringNotContainsString(
            $slug,
            $this->readiness->run('sweepstakes.rules_mentions')['detail']
        );
    }

    /** Une adresse americaine mentionnant le pays reste une adresse americaine. */
    public function testUneAdresseAmericaineAvecLePaysNEstPasSignalee(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_sponsor_address' => '1 Demo Street, Suite 100, New York, NY 10001, USA',
        ]);

        self::assertStringNotContainsString(
            $slug,
            $this->readiness->run('legal.amoe_address')['detail']
        );
    }

    /** Une adresse de sponsor absente est un defaut, pas un cas a ignorer. */
    public function testUneAdresseDeSponsorAbsenteEstSignalee(): void
    {
        $slug = $this->creerConcours([
            'sweepstake_status' => 'published',
            'sweepstake_sponsor_address' => '',
        ]);

        $verdict = $this->readiness->run('legal.amoe_address');

        self::assertTrue($verdict['open']);
        self::assertStringContainsString($slug, $verdict['detail']);
    }

    // ------------------------------------------------------------------ Fixtures

    /**
     * Concours fabrique a partir d'un concours existant.
     *
     * Copier une ligne reelle plutot que d'enumerer les colonnes : le test ne
     * casse pas a chaque migration qui ajoute une colonne NOT NULL, et il
     * exerce le schema tel qu'il est vraiment.
     *
     * @param array<string,mixed> $overrides
     * @return string le slug du concours cree
     */
    private function creerConcours(array $overrides): string
    {
        $modele = $this->connection->fetchAssociative(
            'SELECT * FROM t_sweepstake ORDER BY sweepstake_id LIMIT 1'
        );
        if ($modele === false) {
            self::markTestSkipped('Aucun concours en base pour servir de modele.');
        }

        unset($modele['sweepstake_id'], $modele['created_at'], $modele['updated_at']);
        $slug = 'test-reserve-' . bin2hex(random_bytes(4));
        $modele['sweepstake_slug'] = $slug;
        $modele['sweepstake_name'] = 'Concours de test';

        $this->connection->insert('t_sweepstake', array_merge($modele, $overrides));

        return $slug;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return string le nom de l'offre creee
     */
    private function creerOffre(array $overrides): string
    {
        $modele = $this->connection->fetchAssociative('SELECT * FROM t_offer ORDER BY offer_id LIMIT 1');
        if ($modele === false) {
            self::markTestSkipped('Aucune offre en base pour servir de modele.');
        }

        unset($modele['offer_id'], $modele['created_at'], $modele['updated_at']);
        $nom = 'Offre de test ' . bin2hex(random_bytes(4));
        $modele['offer_name'] = $nom;

        $this->connection->insert('t_offer', array_merge($modele, $overrides));

        return $nom;
    }
}
