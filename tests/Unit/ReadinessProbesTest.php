<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Admin\Services\ReadinessProbes;
use PHPUnit\Framework\TestCase;

/**
 * Les jugements des controles d'ouverture.
 *
 * Ces controles ont un pouvoir particulier : quand ils passent au vert, plus
 * personne ne regarde. Un faux vert n'est donc pas une imprecision, c'est une
 * affirmation fausse — « quelqu'un a verifie » — sur un point qui expose a une
 * sanction. Les tests ci-dessous portent d'abord sur ce risque-la.
 *
 * Les extraits utilises viennent du contenu reellement servi par
 * legals.confluent-digital.com au moment de l'ecriture.
 */
final class ReadinessProbesTest extends TestCase
{
    /**
     * Le piege qui a reellement fait passer un point bloquant au vert.
     *
     * La politique de confidentialite anglaise est une politique RGPD traduite,
     * ou le mot « California » n'apparait pas une seule fois. Elle contient en
     * revanche un intertitre « WHAT HAPPENS IF YOU DO NOT SELL US YOUR DATA ? ».
     * Un marqueur « do not sell » y trouvait donc son compte.
     */
    public function testUnePhraseSansRapportNeVautPasMentionCalifornienne(): void
    {
        $texte = 'WHAT HAPPENS IF YOU DO NOT SELL US YOUR DATA? When using Confluent Digital '
            . 'services, you are informed that your data may be processed. You may lodge a complaint '
            . 'with a supervisory authority, such as the National Commission for Informatics and '
            . 'Liberties (CNIL).';

        self::assertNull(ReadinessProbes::mentionsCaliforniaRights($texte));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mentionsCalifornienne(): array
    {
        return [
            'lien statutaire' => ['Do Not Sell or Share My Personal Information'],
            'sigle' => ['Your rights under the CCPA are described below.'],
            'sigle revise' => ['CPRA rights'],
            'intertitre' => ['California Privacy Rights'],
            'qualite du lecteur' => ['If you are a California resident, you may request...'],
        ];
    }

    /** @dataProvider mentionsCalifornienne */
    public function testUneVraieMentionCalifornienneEstReconnue(string $texte): void
    {
        self::assertNotNull(ReadinessProbes::mentionsCaliforniaRights($texte));
    }

    /**
     * Le document servi sous le nom « conditions generales » en anglais est en
     * realite la politique de protection des donnees. C'est ce qui rend le
     * texte de consentement obligatoire — qui renvoie aux « Terms of Service » —
     * impossible a honorer.
     */
    public function testUnePolitiqueDeDonneesServieCommeCguEstDetectee(): void
    {
        $texte = '<h1>CONFLUENT DIGITAL PERSONAL DATA PROTECTION POLICY</h1><p>Last updated...</p>';

        self::assertSame(
            'personal data protection policy',
            ReadinessProbes::looksLikePrivacyPolicy($texte)
        );
    }

    public function testDeVraiesConditionsGeneralesNeSontPasSignalees(): void
    {
        $texte = '<h1>TERMS OF SERVICE</h1><p>These terms govern your use of the website. '
            . 'By entering a sweepstakes you agree to the Official Rules.</p>';

        self::assertNull(ReadinessProbes::looksLikePrivacyPolicy($texte));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function adresses(): array
    {
        return [
            'adresse americaine' => ['1 Demo Street, Suite 100, New York, NY 10001', true],
            'code postal etendu' => ['500 Main St, Austin, TX 78701-1234', true],
            'territoire' => ['1 Calle Sol, San Juan, PR 00901', true],
            'espaces en trop' => ['  1 Demo Street, New York, NY 10001  ', true],
            // Une adresse AMOE s'adresse a des expediteurs internationaux : elle
            // se termine tres normalement par le pays. La rejeter laissait le
            // controle au rouge sur une adresse pourtant correcte.
            'pays ajoute apres le code' => ['1 Demo Street, New York, NY 10001, USA', true],
            'pays en toutes lettres' => ['PO Box 12, Austin, TX 78701, United States', true],
            'pays abrege avec points' => ['500 Main St, Austin, TX 78701-1234, U.S.A.', true],
            'point final' => ['PO Box 12, Austin, TX 78701.', true],
            'adresse francaise' => ['Espace Wojo, 15 rue des Cuirassiers, 69003 Lyon, France', false],
            'ville seule' => ['Confluent Digital', false],
            'autre pays apres le code' => ['1 Demo Street, New York, NY 10001, Canada', false],
        ];
    }

    /** @dataProvider adresses */
    public function testFormeAmericaineDUneAdressePostale(string $adresse, bool $attendu): void
    {
        self::assertSame($attendu, ReadinessProbes::looksLikeUsAddress($adresse));
    }

    public function testAnnonceursCitesDansUnePage(): void
    {
        $page = '<ul><li>Courtepaille</li><li>Buffalo Grill</li></ul>';

        self::assertSame([], ReadinessProbes::names($page, ['Demo Advertiser', 'Acme Rewards']));
        self::assertSame(['Buffalo Grill'], ReadinessProbes::names($page, ['Buffalo Grill', 'Acme']));
    }

    /** Une chaine vide ne doit jamais compter comme un annonceur trouve. */
    public function testUnNomVideNeCompteJamais(): void
    {
        self::assertSame([], ReadinessProbes::names('n\'importe quel texte', ['']));
    }
}
