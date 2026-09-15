<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Config;
use App\Core\Database;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Services\Totp;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Double authentification : rejeu, codes de secours, activation.
 *
 * Ces regles ne se verifient pas a la main : un essai chronometre depend de
 * l'instant ou il tombe dans la fenetre de trente secondes, et donne des
 * resultats differents selon la seconde. C'est exactement ce qui m'a fait
 * croire un instant a une faille inexistante. Les tests ci-dessous fixent la
 * periode au lieu de la subir.
 *
 * Tout se joue dans une transaction annulee : la base de developpement en
 * ressort inchangee.
 */
final class TwoFactorTest extends TestCase
{
    private Connection $connection;
    private AdminUserRepository $users;
    private int $userId;

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

        $this->users = new AdminUserRepository($database);
        $this->connection->beginTransaction();

        $this->userId = $this->users->create(
            'test-2fa-' . bin2hex(random_bytes(4)) . '@example.invalid',
            'Compte de test',
            'MotDePasseDeTest123',
            'viewer'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testUnCompteNeufNAPasDeDoubleAuthentification(): void
    {
        self::assertFalse(AdminUserRepository::hasTwoFactor($this->utilisateur()));
    }

    /**
     * Un secret pose mais jamais confirme ne doit PAS compter comme actif :
     * une configuration abandonnee en cours de route enfermerait dehors.
     */
    public function testUnSecretNonConfirmeNActivePas(): void
    {
        $this->users->startTwoFactor($this->userId, Totp::generateSecret());

        self::assertFalse(AdminUserRepository::hasTwoFactor($this->utilisateur()));
    }

    public function testLaConfirmationActive(): void
    {
        $secret = Totp::generateSecret();
        $this->users->startTwoFactor($this->userId, $secret);
        $this->users->confirmTwoFactor($this->userId, intdiv(time(), Totp::PERIOD));

        self::assertTrue(AdminUserRepository::hasTwoFactor($this->utilisateur()));
    }

    /**
     * Le rejeu : c'est la regle que le mecanisme existe pour tenir.
     *
     * Un code TOTP reste valide trente secondes. Sans memoire de la derniere
     * periode consommee, un code intercepte — par-dessus l'epaule, dans un
     * journal de proxy, sur une capture d'ecran — se rejoue dans cet
     * intervalle.
     */
    public function testUnePeriodeNeSeConsommePasDeuxFois(): void
    {
        $periode = intdiv(time(), Totp::PERIOD);

        self::assertTrue($this->users->consumePeriod($this->userId, $periode));
        self::assertFalse($this->users->consumePeriod($this->userId, $periode));
    }

    /** Une periode ANTERIEURE est refusee elle aussi : on n'avance jamais a reculons. */
    public function testUnePeriodeAnterieureEstRefusee(): void
    {
        $periode = intdiv(time(), Totp::PERIOD);
        $this->users->consumePeriod($this->userId, $periode);

        self::assertFalse($this->users->consumePeriod($this->userId, $periode - 1));
        self::assertFalse($this->users->consumePeriod($this->userId, $periode - 10));
    }

    public function testUnePeriodeSuivanteEstAcceptee(): void
    {
        $periode = intdiv(time(), Totp::PERIOD);
        $this->users->consumePeriod($this->userId, $periode);

        self::assertTrue($this->users->consumePeriod($this->userId, $periode + 1));
    }

    public function testLesCodesDeSecoursSontAUsageUnique(): void
    {
        $codes = $this->users->resetRecoveryCodes($this->userId, 5);

        self::assertCount(5, $codes);
        self::assertSame(5, $this->users->countUnusedRecoveryCodes($this->userId));

        self::assertTrue($this->users->consumeRecoveryCode($this->userId, $codes[0]));
        self::assertFalse($this->users->consumeRecoveryCode($this->userId, $codes[0]));
        self::assertSame(4, $this->users->countUnusedRecoveryCodes($this->userId));
    }

    public function testUnCodeDeSecoursEtrangerEstRefuse(): void
    {
        $this->users->resetRecoveryCodes($this->userId, 3);

        self::assertFalse($this->users->consumeRecoveryCode($this->userId, 'AAAAA-BBBBB'));
        self::assertFalse($this->users->consumeRecoveryCode($this->userId, ''));
    }

    /** Regenerer invalide les anciens : c'est tout l'objet de la manoeuvre. */
    public function testLaRegenerationInvalideLesAnciensCodes(): void
    {
        $anciens = $this->users->resetRecoveryCodes($this->userId, 3);
        $this->users->resetRecoveryCodes($this->userId, 3);

        self::assertFalse($this->users->consumeRecoveryCode($this->userId, $anciens[0]));
        self::assertSame(3, $this->users->countUnusedRecoveryCodes($this->userId));
    }

    /** Les codes ne sont jamais stockes en clair — au meme titre qu'un mot de passe. */
    public function testLesCodesNeSontPasStockesEnClair(): void
    {
        $codes = $this->users->resetRecoveryCodes($this->userId, 3);

        $stockes = $this->connection->fetchFirstColumn(
            'SELECT admin_recovery_code_hash FROM t_admin_recovery_code WHERE admin_recovery_code_id_user = ?',
            [$this->userId]
        );

        foreach ($codes as $code) {
            self::assertNotContains($code, $stockes);
        }
        foreach ($stockes as $hash) {
            self::assertStringStartsWith('$2y$', (string) $hash);
        }
    }

    /** Desactiver efface le secret ET les codes de secours restants. */
    public function testLaDesactivationEfaceToutEtNeLaisseRien(): void
    {
        $secret = Totp::generateSecret();
        $this->users->startTwoFactor($this->userId, $secret);
        $this->users->confirmTwoFactor($this->userId, intdiv(time(), Totp::PERIOD));
        $this->users->resetRecoveryCodes($this->userId, 5);

        $this->users->disableTwoFactor($this->userId);

        self::assertFalse(AdminUserRepository::hasTwoFactor($this->utilisateur()));
        self::assertSame(0, $this->users->countUnusedRecoveryCodes($this->userId));
        self::assertNull($this->utilisateur()['admin_user_totp_secret']);
    }

    /** @return array<string,mixed> */
    private function utilisateur(): array
    {
        $user = $this->users->findById($this->userId);
        self::assertNotNull($user);
        return $user;
    }
}
