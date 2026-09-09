<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Core\Config;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Fragments juridiques mutualises, servis par legals.confluent-digital.com.
 *
 * Le service renvoie un FRAGMENT HTML, jamais une page complete : il s'insere
 * dans notre propre gabarit.
 *
 * Deux garde-fous :
 *
 * 1. **Cache disque.** Une page legale ne change pas plusieurs fois par heure,
 *    et le chemin critique d'un trafic paye ne doit pas dependre de la latence
 *    d'un service tiers.
 *
 * 2. **Repli explicite.** Si le service ne repond pas, on sert la derniere
 *    version connue, meme perimee. Une page legale vide est une non-conformite,
 *    pas un incident d'affichage.
 */
final class LegalContentService
{
    /** Pages connues : chemin public => page cote service de contenus. */
    public const PAGES = [
        'privacy' => 'politique-vie-privee',
        'terms' => 'conditions-generales',
        'legal' => 'mentions-legales',
        'partners' => 'partenaires',
        'cookies' => 'cookies',
    ];

    public function __construct(
        private Config $config,
        private Client $http,
        private LoggerInterface $logger,
        private string $cacheDir,
    ) {
    }

    public function isKnownPage(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }

    /**
     * Fragment de la page demandee, ou null si elle est introuvable et
     * qu'aucune version en cache n'existe.
     */
    public function fragment(string $page): ?string
    {
        if (!$this->isKnownPage($page)) {
            return null;
        }

        $cacheFile = $this->cacheFile($page);
        $ttl = $this->config->int('LEGALS_CACHE_TTL', 3600);

        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $ttl) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $fresh = $this->fetch(self::PAGES[$page]);
        if ($fresh !== null && trim($fresh) !== '') {
            $this->store($cacheFile, $fresh);
            return $fresh;
        }

        // Repli : la derniere version connue, meme perimee.
        if (is_file($cacheFile)) {
            $stale = @file_get_contents($cacheFile);
            if (is_string($stale) && $stale !== '') {
                $this->logger->warning('Contenu legal servi depuis un cache perime', ['page' => $page]);
                return $stale;
            }
        }

        $this->logger->error('Contenu legal indisponible et absent du cache', ['page' => $page]);
        return null;
    }

    private function fetch(string $remotePage): ?string
    {
        $base = rtrim((string) $this->config->get('LEGALS_BASE_URL', ''), '/');
        if ($base === '') {
            return null;
        }

        try {
            $response = $this->http->get($base . '/', [
                'query' => [
                    'page' => $remotePage,
                    'lang' => $this->config->get('LEGALS_LANG', 'en'),
                    'domain_name' => $this->config->get('APP_DOMAIN', ''),
                ],
                'timeout' => 4,
                'connect_timeout' => 2,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }
            return (string) $response->getBody();
        } catch (\Throwable $e) {
            $this->logger->warning('Service de contenus legaux injoignable', [
                'page' => $remotePage,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function store(string $file, string $content): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return;
        }
        // Ecriture atomique : une requete concurrente ne doit jamais lire un
        // fragment tronque.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $content) !== false) {
            @rename($tmp, $file);
        }
    }

    private function cacheFile(string $page): string
    {
        return $this->cacheDir . '/' . preg_replace('/[^a-z0-9_-]/', '', $page) . '.html';
    }
}
