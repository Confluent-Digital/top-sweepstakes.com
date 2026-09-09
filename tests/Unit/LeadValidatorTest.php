<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Leads\Services\LeadValidator;
use PHPUnit\Framework\TestCase;

final class LeadValidatorTest extends TestCase
{
    private LeadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LeadValidator();
    }

    /** @return list<array<string,mixed>> */
    private function fields(array $keys, bool $required = true): array
    {
        return array_map(
            static fn(string $k): array => [
                'sweepstake_field_key' => $k,
                'sweepstake_field_required' => $required ? 1 : 0,
            ],
            $keys
        );
    }

    /** @return array<string,mixed> */
    private function sweepstake(array $overrides = []): array
    {
        return $overrides + [
            'sweepstake_min_age' => 18,
            'sweepstake_excluded_states' => '',
        ];
    }

    // ---------------------------------------------------------------- obligatoires

    public function testUnChampObligatoireVideProduitUneErreur(): void
    {
        $result = $this->validator->validate([], $this->fields(['email']), $this->sweepstake());
        self::assertArrayHasKey('email', $result['errors']);
    }

    public function testUnChampFacultatifVideNeProduitPasDErreur(): void
    {
        $result = $this->validator->validate([], $this->fields(['phone'], false), $this->sweepstake());
        self::assertSame([], $result['errors']);
        self::assertSame('', $result['values']['phone']);
    }

    public function testUnChampInconnuEstIgnore(): void
    {
        $fields = [['sweepstake_field_key' => 'revenu', 'sweepstake_field_required' => 1]];
        $result = $this->validator->validate(['revenu' => 'x'], $fields, $this->sweepstake());
        self::assertSame([], $result['errors']);
        self::assertSame([], $result['values']);
    }

    // ---------------------------------------------------------------- email

    public function testEmailValide(): void
    {
        $result = $this->validator->validate(
            ['email' => 'John.Doe@Example.COM'],
            $this->fields(['email']),
            $this->sweepstake()
        );
        self::assertSame([], $result['errors']);
        self::assertSame('john.doe@example.com', $result['values']['email']);
    }

    public function testEmailInvalide(): void
    {
        foreach (['john', 'john@', '@example.com', 'john @example.com'] as $bad) {
            $result = $this->validator->validate(['email' => $bad], $this->fields(['email']), $this->sweepstake());
            self::assertArrayHasKey('email', $result['errors'], "attendu invalide : $bad");
        }
    }

    // ---------------------------------------------------------------- adresse US

    public function testCodePostal(): void
    {
        foreach (['10001', '10001-4321'] as $good) {
            $result = $this->validator->validate(['zip' => $good], $this->fields(['zip']), $this->sweepstake());
            self::assertSame([], $result['errors'], "attendu valide : $good");
        }
        foreach (['1000', '100011', 'ABCDE', '10001-12'] as $bad) {
            $result = $this->validator->validate(['zip' => $bad], $this->fields(['zip']), $this->sweepstake());
            self::assertArrayHasKey('zip', $result['errors'], "attendu invalide : $bad");
        }
    }

    public function testEtat(): void
    {
        $result = $this->validator->validate(['state' => 'ny'], $this->fields(['state']), $this->sweepstake());
        self::assertSame([], $result['errors']);
        self::assertSame('NY', $result['values']['state']);

        foreach (['XX', 'New York', 'PR'] as $bad) {
            $result = $this->validator->validate(['state' => $bad], $this->fields(['state']), $this->sweepstake());
            self::assertArrayHasKey('state', $result['errors'], "attendu invalide : $bad");
        }
    }

    // ---------------------------------------------------------------- telephone NANP

    public function testTelephoneNanp(): void
    {
        foreach (['2125550147', '(212) 555-0147', '212-555-0147', '1 212 555 0147'] as $good) {
            $result = $this->validator->validate(['phone' => $good], $this->fields(['phone']), $this->sweepstake());
            self::assertSame([], $result['errors'], "attendu valide : $good");
            self::assertSame('2125550147', $result['values']['phone']);
        }
    }

    public function testTelephoneInvalide(): void
    {
        // Indicatif regional ou prefixe d'echange commencant par 0 ou 1, ou
        // mauvaise longueur : hors plan de numerotation nord-americain.
        foreach (['1125550147', '0125550147', '2121550147', '212555014', '21255501470'] as $bad) {
            $result = $this->validator->validate(['phone' => $bad], $this->fields(['phone']), $this->sweepstake());
            self::assertArrayHasKey('phone', $result['errors'], "attendu invalide : $bad");
        }
    }

    // ---------------------------------------------------------------- age

    public function testAgeMinimumRespecte(): void
    {
        $dob = (new \DateTimeImmutable('-25 years'))->format('Y-m-d');
        $result = $this->validator->validate(['dob' => $dob], $this->fields(['dob']), $this->sweepstake());
        self::assertSame([], $result['errors']);
        self::assertSame($dob, $result['values']['dob']);
    }

    /** L'age minimum figure dans les Official Rules : il refuse explicitement. */
    public function testTropJeuneEstRefuseAvecUnMessageExplicite(): void
    {
        $dob = (new \DateTimeImmutable('-16 years'))->format('Y-m-d');
        $result = $this->validator->validate(['dob' => $dob], $this->fields(['dob']), $this->sweepstake());
        self::assertArrayHasKey('dob', $result['errors']);
        self::assertStringContainsString('18', $result['errors']['dob']);
    }

    public function testAgeMinimumParametrable(): void
    {
        $dob = (new \DateTimeImmutable('-19 years'))->format('Y-m-d');
        $result = $this->validator->validate(
            ['dob' => $dob],
            $this->fields(['dob']),
            $this->sweepstake(['sweepstake_min_age' => 21])
        );
        self::assertArrayHasKey('dob', $result['errors']);
        self::assertStringContainsString('21', $result['errors']['dob']);
    }

    public function testFormatAmericainAccepteEtNormalise(): void
    {
        $result = $this->validator->validate(['dob' => '06/15/1990'], $this->fields(['dob']), $this->sweepstake());
        self::assertSame([], $result['errors']);
        self::assertSame('1990-06-15', $result['values']['dob']);
    }

    public function testDateDeNaissanceInvalide(): void
    {
        foreach (['15/06/1990', '1990-13-01', 'hier', '1990-02-30'] as $bad) {
            $result = $this->validator->validate(['dob' => $bad], $this->fields(['dob']), $this->sweepstake());
            self::assertArrayHasKey('dob', $result['errors'], "attendu invalide : $bad");
        }
    }

    public function testDateDeNaissanceDansLeFutur(): void
    {
        $dob = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $result = $this->validator->validate(['dob' => $dob], $this->fields(['dob']), $this->sweepstake());
        self::assertArrayHasKey('dob', $result['errors']);
    }

    // ---------------------------------------------------------------- Etats exclus

    /**
     * C'est cette colonne qui refuse effectivement le participant : les
     * Official Rules l'annoncent, le code doit l'appliquer.
     */
    public function testUnEtatExcluEstRefuseAvecSonNomEnClair(): void
    {
        $result = $this->validator->validate(
            ['state' => 'NY'],
            $this->fields(['state']),
            $this->sweepstake(['sweepstake_excluded_states' => 'NY, FL'])
        );
        self::assertArrayHasKey('state', $result['errors']);
        self::assertStringContainsString('New York', $result['errors']['state']);
    }

    public function testUnEtatNonExcluPasse(): void
    {
        $result = $this->validator->validate(
            ['state' => 'CA'],
            $this->fields(['state']),
            $this->sweepstake(['sweepstake_excluded_states' => 'NY,FL'])
        );
        self::assertSame([], $result['errors']);
    }

    public function testLaListeDExclusionToleraLaCasseEtLesEspaces(): void
    {
        $result = $this->validator->validate(
            ['state' => 'FL'],
            $this->fields(['state']),
            $this->sweepstake(['sweepstake_excluded_states' => ' ny ,  fl '])
        );
        self::assertArrayHasKey('state', $result['errors']);
    }

    /** Un Etat invalide ne doit pas empiler deux messages contradictoires. */
    public function testUnEtatInvalideNeProduitQuUnSeulMessage(): void
    {
        $result = $this->validator->validate(
            ['state' => 'XX'],
            $this->fields(['state']),
            $this->sweepstake(['sweepstake_excluded_states' => 'XX'])
        );
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('valid U.S. state', $result['errors']['state']);
    }

    // ---------------------------------------------------------------- ensemble

    public function testFormulaireCompletValide(): void
    {
        $input = [
            'email' => 'john@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'phone' => '(212) 555-0147',
            'dob' => '1990-06-15',
            'gender' => 'Male',
        ];
        $fields = $this->fields(array_keys($input));

        $result = $this->validator->validate($input, $fields, $this->sweepstake());

        self::assertSame([], $result['errors']);
        self::assertSame('male', $result['values']['gender']);
        self::assertSame('2125550147', $result['values']['phone']);
    }
}
