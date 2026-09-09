<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Config;
use App\Core\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    private function signer(string $secret = 'secret-de-test-0123456789abcdef'): Signer
    {
        return new Signer(new Config(['APP_SECRET' => $secret]));
    }

    public function testLaSignatureEstStable(): void
    {
        $signer = $this->signer();
        self::assertSame($signer->sign('offer:42'), $signer->sign('offer:42'));
    }

    public function testDeuxPayloadsDifferentsDonnentDesSignaturesDifferentes(): void
    {
        $signer = $this->signer();
        self::assertNotSame($signer->sign('offer:42'), $signer->sign('offer:43'));
    }

    public function testVerifyAccepteLaBonneSignature(): void
    {
        $signer = $this->signer();
        self::assertTrue($signer->verify('offer:42', $signer->sign('offer:42')));
    }

    public function testVerifyRefuseUneSignatureForgee(): void
    {
        $signer = $this->signer();
        self::assertFalse($signer->verify('offer:42', 'deadbeefdeadbeef'));
        self::assertFalse($signer->verify('offer:42', ''));
    }

    /**
     * Le secret fait partie de la signature : une cle differente ne doit pas
     * valider un identifiant d'offre signe ailleurs.
     */
    public function testUnAutreSecretNeValidePas(): void
    {
        $signature = $this->signer('premier-secret-0123456789abcdef')->sign('offer:42');
        self::assertFalse($this->signer('second-secret-0123456789abcdef')->verify('offer:42', $signature));
    }

    public function testLaSignatureLeveSiLeSecretNEstPasConfigure(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Signer(new Config([])))->sign('offer:42');
    }
}
