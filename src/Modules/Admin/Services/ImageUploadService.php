<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Televersement des visuels de concours et d'offres.
 *
 * Le back-office est le seul endroit du site qui accepte un fichier. C'est donc
 * la surface la plus sensible qu'il expose, et elle est traitee en consequence.
 *
 * **Le fichier depose n'est jamais servi tel quel.** Il est decode par GD puis
 * reencode : ce qui sort est une image reconstruite pixel par pixel. Un fichier
 * polyglotte — une image valide qui embarque du PHP dans ses metadonnees, ou un
 * SVG contenant du script — ne survit pas a cette operation. Se contenter de
 * verifier le type MIME laisserait passer les deux.
 *
 * Le nom est **genere**, jamais repris du client : un nom fourni permet de
 * choisir son extension, donc de deposer un `.php`.
 */
final class ImageUploadService
{
    /** Types reels acceptes, deduits du contenu et non de l'extension. */
    private const ACCEPTED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Au-dela, on redimensionne : un visuel de dotation n'a aucun besoin d'etre plus large. */
    private const MAX_WIDTH = 1200;
    private const MAX_HEIGHT = 1200;

    /** Garde anti-bombe de decompression : une image minuscule peut declarer des dimensions enormes. */
    private const MAX_PIXELS = 40_000_000;

    /**
     * Qualite d'encodage, JPEG comme WebP.
     *
     * Mesure sur un visuel de dotation type : 82 rend 64 Ko en JPEG et 33 Ko en
     * WebP, contre 49 et 26 Ko a 70. Les 7 Ko de WebP separant 76 de 82 ne se
     * voient pas a l'ecran, mais on garde 82 : le WebP est deja a la moitie du
     * JPEG, et une dotation floue coute plus cher en conversion que le poids
     * qu'elle economise.
     */
    private const QUALITY = 82;

    public function __construct(private string $publicDir)
    {
    }

    /**
     * Enregistre l'image et rend son nom de repli et ses dimensions.
     *
     * Deux fichiers sont ecrits : le repli (`<base>.jpg` ou `<base>.png`) et sa
     * version WebP (`<base>.webp`), pour que le gabarit puisse proposer les deux.
     *
     * @param string $relativeDir chemin sous `public/`, par exemple `img/offers`
     * @return array{ok: bool, file?: string, width?: int, height?: int, error?: string}
     */
    public function store(UploadedFileInterface $file, string $relativeDir, string $basename): array
    {
        $error = $this->reject($file);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $stream = $file->getStream();
        $stream->rewind();
        $binary = (string) $stream->getContents();

        $type = $this->detectType($binary);
        if ($type === null) {
            return ['ok' => false, 'error' => 'Format non reconnu. Formats acceptes : JPEG, PNG, GIF, WebP.'];
        }

        $dimensions = @getimagesizefromstring($binary);
        if ($dimensions === false) {
            return ['ok' => false, 'error' => 'Image illisible.'];
        }
        if ($dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            return ['ok' => false, 'error' => 'Image trop grande (nombre de pixels excessif).'];
        }

        // Le decodage puis le reencodage sont la vraie protection : ils
        // reconstruisent l'image et ne conservent rien de ce qui l'accompagnait.
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return ['ok' => false, 'error' => 'Image illisible.'];
        }

        $image = $this->resize($image);
        $width = imagesx($image);
        $height = imagesy($image);

        $directory = rtrim($this->publicDir, '/') . '/' . trim($relativeDir, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($image);
            return ['ok' => false, 'error' => 'Repertoire de destination inaccessible : ' . $relativeDir];
        }

        $safeBase = $this->sanitizeBasename($basename);
        // PNG pour les sources a transparence, JPEG sinon : un aplat de couleur
        // derriere un logo detoure se verrait.
        $fallbackExtension = in_array($type, ['png', 'gif', 'webp'], true) ? 'png' : 'jpg';

        $written = $this->write($image, $directory, $safeBase, $fallbackExtension);
        imagedestroy($image);

        if (!$written) {
            return ['ok' => false, 'error' => 'Ecriture impossible sur le disque.'];
        }

        return [
            'ok' => true,
            'file' => $safeBase . '.' . $fallbackExtension,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function reject(UploadedFileInterface $file): ?string
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux.',
                UPLOAD_ERR_PARTIAL => 'Televersement interrompu, recommencez.',
                default => 'Televersement impossible (code ' . $file->getError() . ').',
            };
        }
        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_BYTES) {
            return sprintf('Fichier trop volumineux (%d Mo maximum).', (int) (self::MAX_BYTES / 1024 / 1024));
        }
        return null;
    }

    /** Type reel, lu dans le contenu — ni l'extension ni l'en-tete client ne sont crus. */
    private function detectType(string $binary): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->buffer($binary);
        return self::ACCEPTED[$mime] ?? null;
    }

    /** @param \GdImage $image */
    private function resize(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $ratio = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1.0);
        if ($ratio >= 1.0) {
            return $image;
        }

        $target = imagecreatetruecolor((int) round($width * $ratio), (int) round($height * $ratio));
        // La transparence doit survivre au redimensionnement, sinon un logo
        // detoure se retrouve sur fond noir.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled(
            $target,
            $image,
            0,
            0,
            0,
            0,
            imagesx($target),
            imagesy($target),
            $width,
            $height
        );
        imagedestroy($image);

        return $target;
    }

    private function write(\GdImage $image, string $directory, string $base, string $extension): bool
    {
        $fallbackPath = $directory . '/' . $base . '.' . $extension;

        $ok = match ($extension) {
            'png' => imagepng($image, $fallbackPath, 9),
            default => imagejpeg($image, $fallbackPath, self::QUALITY),
        };
        if (!$ok) {
            return false;
        }

        if ($extension === 'png') {
            $this->quantize($fallbackPath);
        }
        @chmod($fallbackPath, 0664);

        // WebP en complement, jamais a la place : un navigateur qui ne le
        // supporte pas doit trouver le repli. Sur une photo il pese environ la
        // moitie du JPEG, et c'est lui que recevra la quasi-totalite du trafic.
        //
        // Mais pas toujours : sur un aplat transparent, un PNG quantifie en 8
        // bits bat le WebP — mesure sur un logo detoure, 4,9 Ko contre 11,1 Ko.
        // Le gabarit sert le WebP en priorite des qu'il existe ; on ne le garde
        // donc que s'il est reellement plus leger, sinon on ferait payer au
        // visiteur le double du necessaire au nom de la modernite du format.
        $webpPath = $directory . '/' . $base . '.webp';
        @unlink($webpPath);

        if (function_exists('imagewebp') && @imagewebp($image, $webpPath, self::QUALITY)) {
            if (filesize($webpPath) < filesize($fallbackPath)) {
                @chmod($webpPath, 0664);
            } else {
                @unlink($webpPath);
            }
        }

        // Les anciens fichiers d'une autre extension deviendraient orphelins et
        // pourraient etre servis a la place du nouveau visuel.
        foreach (['jpg', 'png'] as $other) {
            if ($other !== $extension) {
                @unlink($directory . '/' . $base . '.' . $other);
            }
        }

        return true;
    }

    /**
     * Quantification du PNG en 8 bits, si pngquant est disponible.
     *
     * GD compresse mais ne quantifie pas : un logo detoure sort en 24 bits et
     * pese trois fois ce qu'il devrait. La transparence est preservee.
     *
     * **L'echec est sans consequence** : le fichier d'origine reste en place et
     * reste servable. Une image moins legere vaut mieux qu'un televersement qui
     * echoue parce qu'un binaire manque.
     */
    private function quantize(string $path): void
    {
        if (!is_executable('/usr/bin/pngquant')) {
            return;
        }

        $temporary = $path . '.quant';
        $command = sprintf(
            '/usr/bin/pngquant --quality=65-90 --speed 3 --strip --force --output %s -- %s 2>/dev/null',
            escapeshellarg($temporary),
            escapeshellarg($path)
        );
        @exec($command, $output, $status);

        // pngquant rend 99 quand il ne tient pas la qualite demandee, et 98
        // quand il ne gagne rien : dans les deux cas on garde l'original.
        if (
            $status === 0 && is_file($temporary) && filesize($temporary) > 0
            && filesize($temporary) < filesize($path)
        ) {
            @rename($temporary, $path);
            return;
        }
        @unlink($temporary);
    }

    /** Nom genere a partir d'une intention, jamais repris du client. */
    private function sanitizeBasename(string $basename): string
    {
        $clean = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $basename) ?? '');
        $clean = trim(preg_replace('/-+/', '-', $clean) ?? '', '-');
        return $clean === '' ? 'image' : mb_substr($clean, 0, 60);
    }
}
