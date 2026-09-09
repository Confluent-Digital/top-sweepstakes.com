<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Offers\Services\TargetingService;
use PHPUnit\Framework\TestCase;

final class TargetingServiceTest extends TestCase
{
    private TargetingService $service;

    protected function setUp(): void
    {
        $this->service = new TargetingService();
    }

    /** @return array<string,mixed> */
    private function rule(string $param, string $operator, string $value): array
    {
        return [
            'offer_targeting_param' => $param,
            'offer_targeting_operator' => $operator,
            'offer_targeting_value' => $value,
        ];
    }

    /** @return array<string,mixed> */
    private function participant(array $overrides = []): array
    {
        return $overrides + [
            'state' => 'NY',
            'zip' => '10001',
            'dob' => '1990-06-15',
            'gender' => 'male',
            'phone' => '2125550147',
            'email' => 'john.doe@gmail.com',
            'subid' => 'aff42',
        ];
    }

    public function testAucuneRegleLaissePasser(): void
    {
        self::assertTrue($this->service->matches([], $this->participant()));
    }

    public function testToutesLesReglesDoiventPasser(): void
    {
        $rules = [
            $this->rule('state', 'in', 'NY,NJ,CT'),
            $this->rule('gender', 'in', 'male'),
        ];
        self::assertTrue($this->service->matches($rules, $this->participant()));

        $rules[] = $this->rule('zip', 'in', '90210');
        self::assertFalse($this->service->matches($rules, $this->participant()));
    }

    public function testInEstInsensibleALaCasseEtAuxEspaces(): void
    {
        $rule = [$this->rule('state', 'in', ' ny , nj ')];
        self::assertTrue($this->service->matches($rule, $this->participant(['state' => 'NY'])));
    }

    public function testNotIn(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('state', 'not_in', 'CA,TX')],
            $this->participant(['state' => 'NY'])
        ));
        self::assertFalse($this->service->matches(
            [$this->rule('state', 'not_in', 'CA,TX')],
            $this->participant(['state' => 'TX'])
        ));
    }

    public function testNotEmpty(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('phone', 'not_empty', '')],
            $this->participant()
        ));
        self::assertFalse($this->service->matches(
            [$this->rule('phone', 'not_empty', '')],
            $this->participant(['phone' => ''])
        ));
    }

    /** Sur une date de naissance, les comparaisons portent sur l'age. */
    public function testComparaisonSurLAgeEtNonSurLaDate(): void
    {
        $participant = $this->participant(['dob' => (new \DateTimeImmutable('-30 years'))->format('Y-m-d')]);

        self::assertTrue($this->service->matches([$this->rule('dob', 'gt', '18')], $participant));
        self::assertTrue($this->service->matches([$this->rule('dob', 'gte', '30')], $participant));
        self::assertTrue($this->service->matches([$this->rule('dob', 'lt', '65')], $participant));
        self::assertFalse($this->service->matches([$this->rule('dob', 'gt', '40')], $participant));
    }

    public function testBetweenSurLAge(): void
    {
        $participant = $this->participant(['dob' => (new \DateTimeImmutable('-45 years'))->format('Y-m-d')]);

        self::assertTrue($this->service->matches([$this->rule('dob', 'between', '25,65')], $participant));
        self::assertFalse($this->service->matches([$this->rule('dob', 'between', '18,30')], $participant));
    }

    public function testBetweenAccepteLesBornesInversees(): void
    {
        $participant = $this->participant(['dob' => (new \DateTimeImmutable('-45 years'))->format('Y-m-d')]);
        self::assertTrue($this->service->matches([$this->rule('dob', 'between', '65,25')], $participant));
    }

    public function testComparaisonSurLeCodePostal(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('zip', 'between', '10000,19999')],
            $this->participant(['zip' => '10001'])
        ));
        self::assertFalse($this->service->matches(
            [$this->rule('zip', 'between', '10000,19999')],
            $this->participant(['zip' => '90210'])
        ));
    }

    public function testZipPlus4EstTolere(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('zip', 'between', '10000,19999')],
            $this->participant(['zip' => '10001-4321'])
        ));
    }

    public function testDomaineEmail(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('email_domain', 'in', 'gmail.com,yahoo.com')],
            $this->participant(['email' => 'John.Doe@GMAIL.com'])
        ));
        self::assertTrue($this->service->matches(
            [$this->rule('email_domain', 'not_in', 'mailinator.com')],
            $this->participant()
        ));
        self::assertFalse($this->service->matches(
            [$this->rule('email_domain', 'in', 'gmail.com')],
            $this->participant(['email' => 'john@outlook.com'])
        ));
    }

    public function testRegex(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('phone', 'regex', '^2\d{9}$')],
            $this->participant(['phone' => '2125550147'])
        ));
        self::assertFalse($this->service->matches(
            [$this->rule('phone', 'regex', '^9\d{9}$')],
            $this->participant(['phone' => '2125550147'])
        ));
    }

    public function testUneRegexContenantLeDelimiteurFonctionne(): void
    {
        self::assertTrue($this->service->matches(
            [$this->rule('subid', 'regex', 'a/b')],
            $this->participant(['subid' => 'xa/by'])
        ));
    }

    // ---------------------------------------------------------------- fail-closed

    /**
     * Le comportement qui distingue ce service de son ancetre : ce qu'on ne
     * sait pas interpreter ECARTE l'offre. L'implementation historique laisse
     * passer, et une regle mal saisie diffuse l'offre a tout le monde.
     */
    public function testUnOperateurInconnuEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('state', 'commence_par', 'N')],
            $this->participant()
        ));
    }

    public function testUnParametreInconnuEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('revenu_annuel', 'gt', '50000')],
            $this->participant()
        ));
    }

    public function testUneRegexInvalideEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('phone', 'regex', '([unclosed')],
            $this->participant()
        ));
    }

    public function testUneRegexVideEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('phone', 'regex', '   ')],
            $this->participant()
        ));
    }

    public function testUnChampNonRenseigneEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('zip', 'in', '10001')],
            $this->participant(['zip' => ''])
        ));
    }

    public function testUneComparaisonNumeriqueSurUnChampTextuelEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('state', 'gt', '5')],
            $this->participant()
        ));
    }

    public function testUneBorneNonNumeriqueEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('dob', 'between', 'jeune,vieux')],
            $this->participant()
        ));
        self::assertFalse($this->service->matches(
            [$this->rule('dob', 'between', '18')],
            $this->participant()
        ));
    }

    public function testUneDateDeNaissanceIllisibleEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('dob', 'gt', '18')],
            $this->participant(['dob' => '15/06/1990'])
        ));
    }

    public function testUneDateDeNaissanceDansLeFuturEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches(
            [$this->rule('dob', 'gt', '18')],
            $this->participant(['dob' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d')])
        ));
    }

    public function testUneRegleVideEcarteLOffre(): void
    {
        self::assertFalse($this->service->matches([[]], $this->participant()));
    }
}
