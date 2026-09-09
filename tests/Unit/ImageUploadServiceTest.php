<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Admin\Services\ImageUploadService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class ImageUploadServiceTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/tsw-upload-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->publicDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->publicDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->publicDir);
    }

    private function service(): ImageUploadService
    {
        return new ImageUploadService($this->publicDir);
    }

    private function upload(string $binary, string $clientName = 'photo.jpg'): UploadedFile
    {
        $stream = (new StreamFactory())->createStream($binary);
        return new UploadedFile($stream, $clientName, 'image/jpeg', strlen($binary), UPLOAD_ERR_OK);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        return $binary;
    }

    /** Photo : dégradé et formes, ce que compresse bien le JPEG et mieux le WebP. */
    private function photo(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $c = imagecolorallocate($image, (int) (30 + $y / $height * 150), 80, 200);
            imageline($image, 0, $y, $width, $y, $c);
        }
        for ($i = 0; $i < 12; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(20, 120),
                random_int(20, 120),
                imagecolorallocate($image, random_int(120, 255), random_int(80, 200), 40)
            );
        }
        ob_start();
        imagejpeg($image, null, 92);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        return $binary;
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        for ($i = 0; $i < 10; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(40, 200),
                random_int(40, 200),
                imagecolorallocate($image, random_int(20, 240), random_int(20, 240), random_int(20, 240))
            );
        }
        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        return $binary;
    }

    // ---------------------------------------------------------------- nominal

    public function testEnregistreUneImageEtRendSesDimensions(): void
    {
        $result = $this->service()->store($this->upload($this->jpeg(600, 400)), 'img/offers', 'offre-7');

        self::assertTrue($result['ok']);
        self::assertSame('offre-7.jpg', $result['file']);
        self::assertSame(600, $result['width']);
        self::assertSame(400, $result['height']);
        self::assertFileExists($this->publicDir . '/img/offers/offre-7.jpg');
    }

    /** Le WebP vient EN PLUS du repli, jamais à la place. */
    public function testUnWebpEstEcritACoteDuRepli(): void
    {
        $this->service()->store($this->upload($this->photo(400, 300)), 'img/offers', 'offre-8');

        self::assertFileExists($this->publicDir . '/img/offers/offre-8.jpg');
        self::assertFileExists($this->publicDir . '/img/offers/offre-8.webp');
    }

    /**
     * Le gabarit sert le WebP dès qu'il existe. Sur un aplat transparent, un
     * PNG quantifié le bat largement : garder le WebP ferait payer au visiteur
     * le double du nécessaire au nom de la modernité du format.
     */
    public function testUnWebpPlusLourdQueLeRepliNEstPasConserve(): void
    {
        $this->service()->store($this->upload($this->png(900, 600)), 'img/offers', 'aplat');

        $fallback = $this->publicDir . '/img/offers/aplat.png';
        self::assertFileExists($fallback);

        $webp = $this->publicDir . '/img/offers/aplat.webp';
        if (is_file($webp)) {
            self::assertLessThan(
                filesize($fallback),
                filesize($webp),
                'un WebP conservé doit être plus léger que son repli'
            );
        } else {
            self::assertTrue(true, 'WebP écarté car plus lourd que le repli');
        }
    }

    public function testLeRepertoireEstCreeSiBesoin(): void
    {
        $result = $this->service()->store($this->upload($this->jpeg(200, 200)), 'img/sweepstakes/42', 'prize');
        self::assertTrue($result['ok']);
        self::assertDirectoryExists($this->publicDir . '/img/sweepstakes/42');
    }

    public function testUneSourceTransparenteEstEnregistreeEnPng(): void
    {
        $result = $this->service()->store($this->upload($this->png(300, 200)), 'img/offers', 'logo');
        self::assertSame('logo.png', $result['file']);
    }

    // ---------------------------------------------------------------- dimensions

    public function testUneImageTropLargeEstRedimensionneeEnGardantSonRatio(): void
    {
        $result = $this->service()->store($this->upload($this->jpeg(2400, 1200)), 'img/offers', 'grande');

        self::assertSame(1200, $result['width']);
        self::assertSame(600, $result['height']);
    }

    public function testUneImagePlusPetiteQueLaLimiteNEstPasAgrandie(): void
    {
        $result = $this->service()->store($this->upload($this->jpeg(320, 200)), 'img/offers', 'petite');
        self::assertSame(320, $result['width']);
        self::assertSame(200, $result['height']);
    }

    // ---------------------------------------------------------------- sécurité

    /**
     * Le cœur de la défense : le fichier n'est jamais servi tel quel. Un
     * polyglotte — image valide portant du PHP dans ses métadonnées — ne
     * survit pas au décodage puis au réencodage.
     */
    public function testUnPayloadEmbarqueNeSurvitPasAuReencodage(): void
    {
        $payload = '<?php system($_GET["c"]); __HALT_COMPILER();';
        $polyglotte = $this->jpeg(200, 150) . $payload;

        $result = $this->service()->store($this->upload($polyglotte), 'img/offers', 'polyglotte');

        self::assertTrue($result['ok']);
        $written = (string) file_get_contents($this->publicDir . '/img/offers/polyglotte.jpg');
        self::assertStringNotContainsString('<?php', $written);
        self::assertStringNotContainsString('system(', $written);
    }

    /** Un nom fourni par le client permettrait de choisir son extension. */
    public function testLeNomDuClientEstIgnore(): void
    {
        $result = $this->service()->store(
            $this->upload($this->jpeg(200, 150), '../../evil.php'),
            'img/offers',
            'offre-9'
        );

        self::assertSame('offre-9.jpg', $result['file']);
        self::assertFileDoesNotExist($this->publicDir . '/evil.php');
    }

    public function testUnNomHostileEstAssaini(): void
    {
        $result = $this->service()->store(
            $this->upload($this->jpeg(120, 120)),
            'img/offers',
            '../../../etc/passwd'
        );

        self::assertTrue($result['ok']);
        self::assertStringNotContainsString('/', (string) $result['file']);
        self::assertStringNotContainsString('..', (string) $result['file']);
        self::assertFileExists($this->publicDir . '/img/offers/' . $result['file']);
    }

    public function testUnFichierQuiNEstPasUneImageEstRefuse(): void
    {
        foreach (['<?php echo 1;', '%PDF-1.4 fake', '<svg onload="alert(1)"></svg>'] as $binary) {
            $result = $this->service()->store($this->upload($binary), 'img/offers', 'faux');
            self::assertFalse($result['ok'], 'attendu refusé : ' . substr($binary, 0, 20));
            self::assertArrayHasKey('error', $result);
        }
    }

    /** Le SVG est refusé volontairement : il peut porter du script. */
    public function testLeSvgEstRefuse(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        self::assertFalse($this->service()->store($this->upload($svg), 'img/offers', 'vecteur')['ok']);
    }

    public function testUnFichierTropVolumineuxEstRefuse(): void
    {
        $stream = (new StreamFactory())->createStream($this->jpeg(100, 100));
        $file = new UploadedFile($stream, 'gros.jpg', 'image/jpeg', 9 * 1024 * 1024, UPLOAD_ERR_OK);

        $result = $this->service()->store($file, 'img/offers', 'gros');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('volumineux', (string) $result['error']);
    }

    public function testUneErreurDeTeleversementEstRapportee(): void
    {
        $stream = (new StreamFactory())->createStream('');
        $file = new UploadedFile($stream, 'x.jpg', 'image/jpeg', 0, UPLOAD_ERR_PARTIAL);

        $result = $this->service()->store($file, 'img/offers', 'partiel');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('interrompu', (string) $result['error']);
    }

    /** Changer de format ne doit pas laisser l'ancien fichier servable. */
    public function testUnAncienFichierDAutreExtensionEstRetire(): void
    {
        $service = $this->service();
        $service->store($this->upload($this->jpeg(200, 200)), 'img/offers', 'remplace');
        self::assertFileExists($this->publicDir . '/img/offers/remplace.jpg');

        $service->store($this->upload($this->png(200, 200)), 'img/offers', 'remplace');
        self::assertFileExists($this->publicDir . '/img/offers/remplace.png');
        self::assertFileDoesNotExist($this->publicDir . '/img/offers/remplace.jpg');
    }
}
