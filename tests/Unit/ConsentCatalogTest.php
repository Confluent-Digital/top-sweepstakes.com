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

    /**
     * L'editeur est nomme dans le consentement MARKETING, et lui seul.
     *
     * La case obligatoire porte une declaration du participant sur son age et
     * sa residence : elle n'a pas a nommer qui que ce soit. Le consentement
     * marketing, lui, autorise un destinataire precis — sans nom, il
     * n'autoriserait personne en particulier, donc n'importe qui.
     */
    public function testLeConsentementMarketingNommeLEditeur(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email']);
        $marketing = $this->parType($consents, ConsentCatalog::MARKETING_EMAIL);

        self::assertStringContainsString('Top Sweepstakes LLC', $marketing['text']);
    }

    public function testLeDomaineSertDeReplisSansSponsorRenseigne(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(['sweepstake_sponsor_name' => '']), ['email']);
        $marketing = $this->parType($consents, ConsentCatalog::MARKETING_EMAIL);

        self::assertStringContainsString('top-sweepstakes.com', $marketing['text']);
    }

    /**
     * AUCUN consentement telephonique n'est propose, quels que soient les
     * champs collectes. Le numero est une donnee d'administration ; presenter
     * une case creerait une obligation de preuve pour un usage qui n'existe
     * pas.
     */
    public function testAucunConsentementTelephoniqueNEstPropose(): void
    {
        $consents = $this->catalog->forSweepstake($this->sweepstake(), ['email', 'phone']);

        $types = array_column($consents, 'type');
        self::assertNotContains(ConsentCatalog::TCPA_PHONE, $types);
        self::assertSame([ConsentCatalog::RULES, ConsentCatalog::MARKETING_EMAIL], $types);

        foreach ($consents as $consent) {
            foreach (['telephone call', 'text message', 'automatic telephone dialing',
                      'prerecorded voice', 'STOP', 'marketing partners'] as $interdit) {
                self::assertStringNotContainsStringIgnoringCase($interdit, $consent['text']);
            }
        }
    }

    /**
     * Les liens portent sur des libelles PRESENTS mot pour mot dans le texte
     * archive : sans quoi l'ancre ne serait jamais posee, et le participant
     * n'aurait aucun moyen d'atteindre le document qu'il accepte.
     */
    public function testLesLiensPortentSurDesLibellesDuTexte(): void
    {
        foreach ($this->catalog->forSweepstake($this->sweepstake(), ['email']) as $consent) {
            foreach ($consent['links'] as $libelle => $url) {
                self::assertStringContainsString($libelle, $consent['text'], $libelle);
                self::assertStringStartsWith('/', $url);
            }
        }
    }

    /**
     * Le rendu HTML porte les MEMES mots que le texte archive : seules des
     * ancres s'y ajoutent. C'est l'invariant qui permet d'opposer la preuve.
     */
    public function testLeRenduPorteLesMemesMotsQueLArchive(): void
    {
        foreach ($this->catalog->forSweepstake($this->sweepstake(), ['email']) as $consent) {
            $rendu = html_entity_decode(
                strip_tags(ConsentCatalog::html($consent)),
                ENT_QUOTES,
                'UTF-8'
            );
            self::assertSame($consent['text'], $rendu);
        }
    }

    /** Le balisage venu d'une valeur de configuration ne doit pas passer. */
    public function testLeTexteEstEchappeAvantDEtreLie(): void
    {
        $consents = $this->catalog->forSweepstake(
            $this->sweepstake(['sweepstake_sponsor_name' => '<script>alert(1)</script>']),
            ['email']
        );
        $rendu = ConsentCatalog::html($this->parType($consents, ConsentCatalog::MARKETING_EMAIL));

        self::assertStringNotContainsString('<script>', $rendu);
        self::assertStringContainsString('&lt;script&gt;', $rendu);
    }

    /**
     * @param list<array<string,mixed>> $consents
     * @return array<string,mixed>
     */
    private function parType(array $consents, string $type): array
    {
        foreach ($consents as $consent) {
            if ($consent['type'] === $type) {
                return $consent;
            }
        }
        self::fail('Consentement introuvable : ' . $type);
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
