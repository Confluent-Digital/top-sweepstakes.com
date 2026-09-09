<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Offers\Services\OfferSelector;
use App\Modules\Offers\Services\TargetingService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OfferSelectorTest extends TestCase
{
    private const SEED = 20260909;

    private function selector(): OfferSelector
    {
        return OfferSelector::seeded(new TargetingService(), self::SEED);
    }

    /** @return array<string,mixed> */
    private function offer(int $id, array $overrides = []): array
    {
        return $overrides + [
            'offer_id' => $id,
            'offer_active' => 1,
            'offer_platform_idv' => (string) (4000 + $id),
            'offer_country' => 'US',
            'offer_date_start' => null,
            'offer_date_end' => null,
            'offer_cap_day' => null,
            'offer_cap_total' => null,
            'offer_weight' => 100,
            'offer_ecpm' => 0.0,
            'offer_ecpm_15d' => 0.0,
            'impressions_today' => 0,
            'impressions_total' => OfferSelector::EXPLORATION_THRESHOLD,
            'targeting_rules' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function participant(array $overrides = []): array
    {
        return $overrides + [
            'country' => 'US',
            'state' => 'NY',
            'zip' => '10001',
            'dob' => '1990-06-15',
            'gender' => 'male',
            'phone' => '2125550147',
            'email' => 'john@gmail.com',
            'subid' => 'aff42',
        ];
    }

    /** @param list<array<string,mixed>> $offers @return list<int> */
    private function ids(array $offers): array
    {
        return array_map(static fn(array $o): int => (int) $o['offer_id'], $offers);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-09');
    }

    // ---------------------------------------------------------------- classement

    public function testClassementParEcpmDecroissant(): void
    {
        $offers = [
            $this->offer(1, ['offer_ecpm_15d' => 2.0]),
            $this->offer(2, ['offer_ecpm_15d' => 9.0]),
            $this->offer(3, ['offer_ecpm_15d' => 5.0]),
        ];
        $selected = $this->selector()->select($offers, $this->participant(), 3, $this->today());
        self::assertSame([2, 3, 1], $this->ids($selected));
    }

    public function testLePoidsPondereLEcpmSansLeRemplacer(): void
    {
        $offers = [
            $this->offer(1, ['offer_ecpm_15d' => 5.0, 'offer_weight' => 100]),
            $this->offer(2, ['offer_ecpm_15d' => 4.0, 'offer_weight' => 200]),
        ];
        // 4 x 2,00 = 8 passe devant 5 x 1,00 = 5.
        self::assertSame([2, 1], $this->ids($this->selector()->select($offers, $this->participant(), 2, $this->today())));
    }

    public function testLEcpmCourantSertDeReplisQuandCeluiA15JoursEstAZero(): void
    {
        $offers = [
            $this->offer(1, ['offer_ecpm_15d' => 0.0, 'offer_ecpm' => 7.0]),
            $this->offer(2, ['offer_ecpm_15d' => 3.0, 'offer_ecpm' => 0.0]),
        ];
        self::assertSame([1, 2], $this->ids($this->selector()->select($offers, $this->participant(), 2, $this->today())));
    }

    public function testLaLimiteEstRespectee(): void
    {
        $offers = [];
        for ($i = 1; $i <= 10; $i++) {
            $offers[] = $this->offer($i, ['offer_ecpm_15d' => (float) $i]);
        }
        self::assertCount(3, $this->selector()->select($offers, $this->participant(), 3, $this->today()));
    }

    public function testUneLimiteNulleOuNegativeNeRendRien(): void
    {
        $offers = [$this->offer(1, ['offer_ecpm_15d' => 5.0])];
        self::assertSame([], $this->selector()->select($offers, $this->participant(), 0, $this->today()));
        self::assertSame([], $this->selector()->select($offers, $this->participant(), -2, $this->today()));
    }

    // ---------------------------------------------------------------- eligibilite

    public function testUneOffreInactiveEstEcartee(): void
    {
        $offers = [$this->offer(1, ['offer_active' => 0]), $this->offer(2)];
        self::assertSame([2], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    /**
     * Une offre sans identifiant de crea ne peut pas etre facturee : l'afficher
     * consommerait des impressions pour rien, et OfferLinkBuilder leverait.
     */
    public function testUneOffreSansIdvEstEcartee(): void
    {
        $offers = [$this->offer(1, ['offer_platform_idv' => '']), $this->offer(2)];
        self::assertSame([2], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    public function testFenetreDeDiffusion(): void
    {
        $offers = [
            $this->offer(1, ['offer_date_end' => '2026-09-08']),      // terminee hier
            $this->offer(2, ['offer_date_start' => '2026-09-10']),    // demarre demain
            $this->offer(3, ['offer_date_start' => '2026-09-01', 'offer_date_end' => '2026-09-30']),
            $this->offer(4, ['offer_date_start' => '2026-09-09', 'offer_date_end' => '2026-09-09']),
        ];
        self::assertSame([3, 4], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    public function testUneDateAvecHeureEstToleree(): void
    {
        $offers = [$this->offer(1, ['offer_date_end' => '2026-09-09 00:00:00'])];
        self::assertSame([1], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    public function testFiltrePays(): void
    {
        $offers = [$this->offer(1, ['offer_country' => 'FR']), $this->offer(2, ['offer_country' => 'US'])];
        self::assertSame([2], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    // ---------------------------------------------------------------- plafonds

    /**
     * Les plafonds sont verifies A L'AFFICHAGE. Dans l'implementation
     * historique ils ne s'appliquent qu'au cron d'envoi, et une offre plafonnee
     * continue de consommer des impressions qui ne seront jamais payees.
     */
    public function testPlafondJournalier(): void
    {
        $offers = [
            $this->offer(1, ['offer_cap_day' => 100, 'impressions_today' => 100]),
            $this->offer(2, ['offer_cap_day' => 100, 'impressions_today' => 99]),
        ];
        self::assertSame([2], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    public function testPlafondTotal(): void
    {
        $offers = [
            $this->offer(1, ['offer_cap_total' => 5000, 'impressions_total' => 5000]),
            $this->offer(2, ['offer_cap_total' => 5000, 'impressions_total' => 4999]),
        ];
        self::assertSame([2], $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today())));
    }

    public function testUnPlafondNulOuAZeroNePlafonnePas(): void
    {
        $offers = [
            $this->offer(1, ['offer_cap_day' => null, 'impressions_today' => 999999]),
            $this->offer(2, ['offer_cap_day' => 0, 'impressions_today' => 999999]),
        ];
        self::assertCount(2, $this->selector()->select($offers, $this->participant(), 5, $this->today()));
    }

    // ---------------------------------------------------------------- ciblage

    public function testLeCiblageEcarteLesOffresNonPertinentes(): void
    {
        $rule = [[
            'offer_targeting_param' => 'state',
            'offer_targeting_operator' => 'in',
            'offer_targeting_value' => 'CA,TX',
        ]];
        $offers = [$this->offer(1, ['targeting_rules' => $rule]), $this->offer(2)];

        self::assertSame([2], $this->ids($this->selector()->select($offers, $this->participant(['state' => 'NY']), 5, $this->today())));
        self::assertCount(2, $this->selector()->select($offers, $this->participant(['state' => 'CA']), 5, $this->today()));
    }

    /** Le fail-closed du ciblage doit se propager jusqu'a la selection. */
    public function testUneRegleIncomprehensibleEcarteLOffre(): void
    {
        $rule = [[
            'offer_targeting_param' => 'state',
            'offer_targeting_operator' => 'operateur_invente',
            'offer_targeting_value' => 'NY',
        ]];
        $offers = [$this->offer(1, ['targeting_rules' => $rule])];
        self::assertSame([], $this->selector()->select($offers, $this->participant(), 5, $this->today()));
    }

    // ---------------------------------------------------------------- exploration

    /**
     * Sans exploration, une offre nouvelle — dont l'eCPM vaut zero — ne serait
     * jamais affichee et ne pourrait donc jamais accumuler d'historique.
     */
    public function testUneOffreNonMesureeObtientUnEmplacement(): void
    {
        $offers = [];
        for ($i = 1; $i <= 8; $i++) {
            $offers[] = $this->offer($i, ['offer_ecpm_15d' => 10.0 - $i]);
        }
        $offers[] = $this->offer(99, ['impressions_total' => 0, 'offer_ecpm_15d' => 0.0]);

        $selected = $this->ids($this->selector()->select($offers, $this->participant(), 5, $this->today()));
        self::assertContains(99, $selected);
        self::assertCount(5, $selected);
    }

    public function testLesOffresExploreesSePlacentEnFinDeBloc(): void
    {
        $offers = [
            $this->offer(1, ['offer_ecpm_15d' => 8.0]),
            $this->offer(2, ['offer_ecpm_15d' => 6.0]),
            $this->offer(99, ['impressions_total' => 0]),
        ];
        self::assertSame([1, 2, 99], $this->ids($this->selector()->select($offers, $this->participant(), 3, $this->today())));
    }

    public function testLesEmplacementsLibresReviennentALAutreGroupe(): void
    {
        // Une seule offre mesuree, quatre non mesurees, six emplacements.
        $offers = [$this->offer(1, ['offer_ecpm_15d' => 8.0])];
        foreach ([91, 92, 93, 94] as $id) {
            $offers[] = $this->offer($id, ['impressions_total' => 0]);
        }
        self::assertCount(5, $this->selector()->select($offers, $this->participant(), 6, $this->today()));
    }

    public function testSansOffreNonMesureeTousLesEmplacementsVontAuxMesurees(): void
    {
        $offers = [];
        for ($i = 1; $i <= 6; $i++) {
            $offers[] = $this->offer($i, ['offer_ecpm_15d' => (float) (10 - $i)]);
        }
        self::assertSame([1, 2, 3, 4], $this->ids($this->selector()->select($offers, $this->participant(), 4, $this->today())));
    }

    /** Le tirage doit etre reproductible sous graine fixee. */
    public function testLeTirageEstDeterministeSousGraine(): void
    {
        $offers = [$this->offer(1, ['offer_ecpm_15d' => 8.0])];
        foreach (range(90, 99) as $id) {
            $offers[] = $this->offer($id, ['impressions_total' => 0]);
        }

        $premier = $this->ids(OfferSelector::seeded(new TargetingService(), 4242)
            ->select($offers, $this->participant(), 4, $this->today()));
        $second = $this->ids(OfferSelector::seeded(new TargetingService(), 4242)
            ->select($offers, $this->participant(), 4, $this->today()));

        self::assertSame($premier, $second);
    }

    public function testAucuneOffreEligibleRendUnTableauVide(): void
    {
        $offers = [$this->offer(1, ['offer_active' => 0])];
        self::assertSame([], $this->selector()->select($offers, $this->participant(), 5, $this->today()));
        self::assertSame([], $this->selector()->select([], $this->participant(), 5, $this->today()));
    }
}
