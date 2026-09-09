<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Leads\Services\SpamGuard;
use PHPUnit\Framework\TestCase;

final class SpamGuardTest extends TestCase
{
    private SpamGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SpamGuard();
    }

    /** @return array<string,mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            SpamGuard::HONEYPOT_FIELD => '',
            SpamGuard::TIMESTAMP_FIELD => (string) (time() - 30),
        ];
    }

    /** @return array<string,string> */
    private function values(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'john.doe@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '2125550147',
        ];
    }

    public function testUneSoumissionNormalePasse(): void
    {
        self::assertNull($this->guard->reject($this->input(), $this->values(['phone' => '2124440147'])));
    }

    // ---------------------------------------------------------------- champ piege

    public function testLeChampPiegeRempliRejette(): void
    {
        self::assertSame(
            'honeypot',
            $this->guard->reject($this->input([SpamGuard::HONEYPOT_FIELD => 'http://spam.example']), $this->values())
        );
    }

    public function testLeChampPiegeVideOuAbsentNeRejettePas(): void
    {
        self::assertNull($this->guard->reject(
            $this->input([SpamGuard::HONEYPOT_FIELD => '   ']),
            $this->values(['phone' => '2124440147'])
        ));
        $input = $this->input();
        unset($input[SpamGuard::HONEYPOT_FIELD]);
        self::assertNull($this->guard->reject($input, $this->values(['phone' => '2124440147'])));
    }

    // ---------------------------------------------------------------- vitesse

    public function testUneSoumissionInstantaneeRejette(): void
    {
        self::assertSame(
            'too_fast',
            $this->guard->reject(
                $this->input([SpamGuard::TIMESTAMP_FIELD => (string) time()]),
                $this->values()
            )
        );
    }

    /**
     * L'horodatage peut manquer pour de bonnes raisons — JavaScript desactive,
     * page servie depuis le cache. On ne conclut rien.
     */
    public function testUnHorodatageAbsentNeRejettePas(): void
    {
        $input = $this->input();
        unset($input[SpamGuard::TIMESTAMP_FIELD]);
        self::assertNull($this->guard->reject($input, $this->values(['phone' => '2124440147'])));
    }

    /** Un onglet laisse ouvert plusieurs heures reste une soumission legitime. */
    public function testUnOngletLaisseOuvertLongtempsNeRejettePas(): void
    {
        self::assertNull($this->guard->reject(
            $this->input([SpamGuard::TIMESTAMP_FIELD => (string) (time() - 86400)]),
            $this->values(['phone' => '2124440147'])
        ));
    }

    // ---------------------------------------------------------------- telephone

    public function testNumerosImpossibles(): void
    {
        // 2121111111 : sept « 1 » consecutifs. Les longueurs aberrantes sont
        // deja ecartees par LeadValidator, SpamGuard ne les revoit pas.
        foreach (['5551234567', '2125550147', '2121111111', '0001234567'] as $phone) {
            self::assertSame(
                'fake_phone',
                $this->guard->reject($this->input(), $this->values(['phone' => $phone])),
                "attendu rejete : $phone"
            );
        }
    }

    /**
     * Le seuil de repetition est volontairement haut (sept chiffres
     * identiques). A quatre, comme dans meilleursconcours.com, on ecarterait
     * « 2125556666 », qui est un numero plausible — et sur du trafic achete,
     * refuser un vrai participant coute plus cher qu'accepter un faux.
     */
    public function testUnVraiNumeroPasse(): void
    {
        foreach (['2124440147', '3104440147', '6172233445', '2124446666'] as $phone) {
            self::assertNull(
                $this->guard->reject($this->input(), $this->values(['phone' => $phone])),
                "attendu accepte : $phone"
            );
        }
    }

    public function testLeIndicatifPaysEstIgnore(): void
    {
        self::assertSame(
            'fake_phone',
            $this->guard->reject($this->input(), $this->values(['phone' => '15551234567']))
        );
    }

    public function testUnTelephoneVideNeRejettePas(): void
    {
        self::assertNull($this->guard->reject($this->input(), $this->values(['phone' => ''])));
    }

    // ---------------------------------------------------------------- mots interdits

    public function testMotsInterdits(): void
    {
        foreach (
            [
                ['first_name' => 'test'],
                ['last_name' => 'asdf'],
                ['email' => 'noreply@example.com'],
                ['email' => 'admin@example.com'],
            ] as $override
        ) {
            self::assertSame(
                'stop_word',
                $this->guard->reject($this->input(), $this->values($override + ['phone' => '2124440147'])),
                'attendu rejete : ' . json_encode($override)
            );
        }
    }

    /**
     * La comparaison est exacte et non « contient » : « Sandra » contient
     * « and », « Testa » est un vrai patronyme, et ecarter un vrai participant
     * coute plus cher qu'accepter un faux sur du trafic achete.
     */
    public function testUnMotInterditInclusDansUnVraiNomNeRejettePas(): void
    {
        foreach (
            [
                ['first_name' => 'Testa'],
                ['last_name' => 'Rootes'],
                ['email' => 'testimony@example.com'],
                ['first_name' => 'Sandra'],
            ] as $override
        ) {
            self::assertNull(
                $this->guard->reject($this->input(), $this->values($override + ['phone' => '2124440147'])),
                'attendu accepte : ' . json_encode($override)
            );
        }
    }

    /** Le suffixe « +etiquette » est courant et legitime. */
    public function testLeSuffixePlusEstRetireAvantComparaison(): void
    {
        self::assertSame(
            'stop_word',
            $this->guard->reject($this->input(), $this->values([
                'email' => 'test+sweepstakes@example.com',
                'phone' => '2124440147',
            ]))
        );
        self::assertNull(
            $this->guard->reject($this->input(), $this->values([
                'email' => 'john+sweepstakes@example.com',
                'phone' => '2124440147',
            ]))
        );
    }
}
