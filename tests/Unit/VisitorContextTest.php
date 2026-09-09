<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Session\ArraySessionStore;
use App\Modules\Sweepstakes\Services\DeviceDetector;
use App\Modules\Sweepstakes\Services\VisitorContext;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Uri;

final class VisitorContextTest extends TestCase
{
    private ArraySessionStore $session;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore();
    }

    private function context(?int $seed = null): VisitorContext
    {
        return new VisitorContext(
            $this->session,
            new DeviceDetector(),
            $seed === null ? null : new Randomizer(new Mt19937($seed))
        );
    }

    private function request(string $query = '', string $userAgent = 'Mozilla/5.0', string $referer = '')
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', new Uri('https', 'top-sweepstakes.com', null, '/amazon-750', $query))
            ->withHeader('User-Agent', $userAgent);

        parse_str($query, $params);
        /** @var array<string,mixed> $params */
        $request = $request->withQueryParams($params);

        return $referer === '' ? $request : $request->withHeader('Referer', $referer);
    }

    // ---------------------------------------------------------------- attribution

    public function testLAttributionEstCaptureeALaPremierePage(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('subid=aff42&utm_source=facebook&fbclid=abc123'));

        $attribution = $context->attribution();
        self::assertSame('aff42', $attribution['subid']);
        self::assertSame('facebook', $attribution['utm_source']);
        self::assertSame('abc123', $attribution['fbclid']);
    }

    /**
     * L'invariant : un participant arrive avec un subid doit sortir avec le
     * meme, sinon le revenu est attribue a la mauvaise source.
     */
    public function testUneSecondePageNEcrasePasLAttribution(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('subid=aff42&utm_source=facebook'));
        $context->bootstrap($this->request('subid=autre&utm_source=google'));

        self::assertSame('aff42', $context->subid());
        self::assertSame('facebook', $context->attribution()['utm_source']);
    }

    public function testLeClickidSertDeSubidQuandLaRegieNEnPassePas(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('clickid=xyz789'));
        self::assertSame('xyz789', $context->subid());
    }

    public function testUnSubidExpliciteLEmporteSurLeClickid(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('subid=aff42&clickid=xyz789'));
        self::assertSame('aff42', $context->subid());
    }

    public function testLeRefererEstConserve(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('', 'Mozilla/5.0', 'https://www.google.com/'));
        self::assertSame('https://www.google.com/', $context->attribution()['referer']);
    }

    public function testUneValeurDAttributionEstBorneeEnLongueur(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('subid=' . str_repeat('x', 400)));
        self::assertSame(255, strlen($context->subid()));
    }

    public function testTraficSansParametre(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request());
        self::assertSame('', $context->subid());
        self::assertSame('', $context->attribution()['utm_source']);
    }

    // ---------------------------------------------------------------- session et appareil

    public function testLIdentifiantDeSessionEstStable(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request());
        $first = $context->sessionUid();

        $context->bootstrap($this->request());
        self::assertSame($first, $context->sessionUid());
        self::assertSame(32, strlen($first));
    }

    public function testDetectionDAppareil(): void
    {
        $context = $this->context();
        $context->bootstrap($this->request('', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148'));
        self::assertSame('mobile', $context->device());
    }

    // ---------------------------------------------------------------- variantes

    /** @return list<array<string,mixed>> */
    private function variants(array $weights): array
    {
        $out = [];
        foreach ($weights as $id => $weight) {
            $out[] = ['sweepstake_variant_id' => $id, 'sweepstake_variant_weight' => $weight];
        }
        return $out;
    }

    /**
     * L'invariant : changer de variante en cours de parcours rend le test A/B
     * ininterpretable et fausse l'attribution des revenus.
     */
    public function testLaVarianteEstFigeePourToutLeParcours(): void
    {
        $context = $this->context(seed: 7);
        $variants = $this->variants([11 => 50, 12 => 50]);

        $first = $context->variantFor(3, $variants);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame($first, $context->variantFor(3, $variants));
        }
    }

    public function testChaqueConcoursTireSaPropreVariante(): void
    {
        $context = $this->context(seed: 7);
        $context->variantFor(3, $this->variants([11 => 100]));
        $context->variantFor(4, $this->variants([21 => 100]));

        self::assertSame(11, $context->variantFor(3, $this->variants([11 => 100])));
        self::assertSame(21, $context->variantFor(4, $this->variants([21 => 100])));
    }

    public function testSansVarianteActiveOnRendZero(): void
    {
        self::assertSame(0, $this->context()->variantFor(3, []));
    }

    public function testUnPoidsNulOuNegatifNEstJamaisTire(): void
    {
        $context = $this->context(seed: 1);
        $variants = $this->variants([11 => 0, 12 => 100]);
        self::assertSame(12, $context->variantFor(3, $variants));
    }

    public function testTousLesPoidsANulRendZero(): void
    {
        self::assertSame(0, $this->context()->variantFor(3, $this->variants([11 => 0, 12 => 0])));
    }

    /**
     * Une variante desactivee entre deux pages ne doit pas continuer a etre
     * servie : le participant est retire au tirage parmi celles qui restent.
     */
    public function testUneVarianteDesactiveeEstRemplacee(): void
    {
        $context = $this->context(seed: 3);
        $context->variantFor(3, $this->variants([11 => 100]));
        self::assertSame(11, $context->variantFor(3, $this->variants([11 => 100])));

        $replacement = $context->variantFor(3, $this->variants([12 => 100]));
        self::assertSame(12, $replacement);
    }

    public function testLaRepartitionSuitLesPoids(): void
    {
        $counts = [11 => 0, 12 => 0];
        for ($i = 0; $i < 400; $i++) {
            $session = new ArraySessionStore();
            $context = new VisitorContext($session, new DeviceDetector(), new Randomizer(new Mt19937($i)));
            $counts[$context->variantFor(3, $this->variants([11 => 80, 12 => 20]))]++;
        }
        // 80/20 attendu ; la borne est large, on teste la tendance, pas le hasard.
        self::assertGreaterThan($counts[12] * 2, $counts[11]);
    }

    // ---------------------------------------------------------------- donnees de formulaire

    public function testLesDonneesDEtapeSontConserveesEntreLesEtapes(): void
    {
        $context = $this->context();
        $context->mergeLead(3, ['email' => 'john@example.com', 'first_name' => 'John']);
        $context->mergeLead(3, ['zip' => '10001']);

        self::assertSame(
            ['zip' => '10001', 'email' => 'john@example.com', 'first_name' => 'John'],
            $context->lead(3)
        );
    }

    public function testUneNouvelleValeurEcraseLAncienne(): void
    {
        $context = $this->context();
        $context->mergeLead(3, ['email' => 'ancien@example.com']);
        $context->mergeLead(3, ['email' => 'nouveau@example.com']);
        self::assertSame('nouveau@example.com', $context->lead(3)['email']);
    }

    public function testLesDonneesSontCloisonneesParConcours(): void
    {
        $context = $this->context();
        $context->mergeLead(3, ['email' => 'a@example.com']);
        $context->mergeLead(4, ['email' => 'b@example.com']);

        self::assertSame('a@example.com', $context->lead(3)['email']);
        self::assertSame('b@example.com', $context->lead(4)['email']);
    }

    public function testOubliDesDonnees(): void
    {
        $context = $this->context();
        $context->mergeLead(3, ['email' => 'a@example.com']);
        $context->forgetLead(3);
        self::assertSame([], $context->lead(3));
    }

    public function testIdentifiantDuParticipantEnregistre(): void
    {
        $context = $this->context();
        self::assertNull($context->leadId(3));
        $context->setLeadId(3, 42);
        self::assertSame(42, $context->leadId(3));
        self::assertNull($context->leadId(4));
    }
}
