<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Config;
use App\Modules\Leads\Services\ConsentCatalog;
use PHPUnit\Framework\TestCase;

final class ConsentCatalogTest extends TestCase
{
    private ConsentCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ConsentCatalog(new Config(['APP_DOMAIN' => 'top-sweepstakes.com']));
    }

    /** @return array<string,mixed> */
    private function sweepstake(array $overrides = []): array
    {
        return $overrides + [
            'sweepstake_sponsor_name' => 'Top Sweepstakes LLC',
            'sweepstake_min_age' => 18,
        ];
    }

    /** @param list<array{type:string,required:bool,text:string}> $consents @return list<string> */
    private function types(array $consents): array
    {
        return array_map(static fn(array $c): string => $c['type'], $consents);
    }

    public function testReglesEtMarketingSontToujoursProposes(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email', 'first_name']);
        self::assertSame([ConsentCatalog::RULES, ConsentCatalog::MARKETING_EMAIL], $this->types($consents));
    }

    /** Un consentement au demarchage sans numero collecte n'a pas d'objet. */
    public function testLeConsentementTcpaNApparaitQueSiLeTelephoneEstCollecte(): void
    {
        $sans = $this->catalog->forSweepstake($this->sweepstake(), ['email']);
        self::assertNotContains(ConsentCatalog::TCPA_PHONE, $this->types($sans));

        $avec = $this->catalog->forSweepstake($this->sweepstake(), ['email', 'phone']);
        self::assertContains(ConsentCatalog::TCPA_PHONE, $this->types($avec));
    }

    public function testSeulLAcceptationDesReglesEstObligatoire(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email', 'phone']);
        foreach ($consents as $consent) {
            self::assertSame(
                $consent['type'] === ConsentCatalog::RULES,
                $consent['required'],
                'obligatoire inattendu pour ' . $consent['type']
            );
        }
    }

    public function testLAgeMinimumFigureDansLeTexteDesRegles(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(['sweepstake_min_age' => 21]), ['email']);
        self::assertStringContainsString('21 years old', $consents[0]['text']);
    }

    public function testLeSponsorEstNommeDansLesTextes(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email']);
        foreach ($consents as $consent) {
            self::assertStringContainsString('Top Sweepstakes LLC', $consent['text']);
        }
    }

    public function testLeDomaineSertDeReplisSansSponsorRenseigne(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(['sweepstake_sponsor_name' => '']), ['email']);
        self::assertStringContainsString('top-sweepstakes.com', $consents[0]['text']);
    }

    /**
     * Le texte TCPA doit nommer explicitement l'appel automatise et le SMS, et
     * dire que le consentement n'est pas une condition de participation.
     */
    public function testLeTexteTcpaPorteLesMentionsExigees(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email', 'phone']);
        $tcpa = $consents[2]['text'];

        self::assertStringContainsString('automatic telephone dialing system', $tcpa);
        self::assertStringContainsString('prerecorded voice', $tcpa);
        self::assertStringContainsString('text messages', $tcpa);
        self::assertStringContainsString('not a condition of entry', $tcpa);
        self::assertStringContainsString('STOP', $tcpa);
    }

    public function testLeTexteMarketingAnnonceLaDesinscription(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email']);
        self::assertStringContainsString('unsubscribe at any time', $consents[1]['text']);
    }

    public function testLEmpreinteEstStableEtSensibleAuTexte(): void
    {
        $a = ConsentCatalog::hash('texte');
        self::assertSame($a, ConsentCatalog::hash('texte'));
        self::assertNotSame($a, ConsentCatalog::hash('texte '));
        self::assertSame(64, strlen($a));
    }
}
