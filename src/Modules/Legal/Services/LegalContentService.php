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
        if ($fresh !== null && $this->looksLikeContent($fresh, $page)) {
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

    /**
     * Le corps recu ressemble-t-il a un texte legal ?
     *
     * Un code 200 ne suffit pas. Quand un fragment n'existe pas dans une langue
     * donnee, le service de contenus repond 200 avec un avertissement PHP :
     * `file_get_contents(...): Failed to open stream`, accompagne d'une trace
     * Xdebug qui contient le chemin absolu de son serveur. Sans ce controle, on
     * affichait cette trace a la place de la politique cookies, on la mettait
     * en cache pour toute la duree du TTL, et le repli documente ne se
     * declenchait jamais puisque le contenu n'etait pas considere comme absent.
     *
     * Deux torts en un : une page legale vide — ce que la regle interdit — et
     * la divulgation d'un chemin interne.
     */
    private function looksLikeContent(string $body, string $page): bool
    {
        $trimmed = trim($body);

        // Un fragment juridique fait quelques milliers de caracteres. Ce seuil
        // ecarte les reponses vides et les messages d'erreur courts sans jamais
        // atteindre un texte reel.
        if (mb_strlen($trimmed) < 200) {
            $this->logger->warning('Contenu legal trop court pour etre un fragment', [
                'page' => $page,
                'length' => mb_strlen($trimmed),
            ]);
            return false;
        }

        foreach (['xdebug-error', 'Fatal error', 'Warning:', 'Notice:', 'Call Stack', '/var/www/'] as $marker) {
            if (stripos($trimmed, $marker) !== false) {
                $this->logger->error('Contenu legal : reponse d\'erreur recue avec un code 200', [
                    'page' => $page,
                    'marker' => $marker,
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Interroge le service de contenus, l'hote interne d'abord.
     *
     * `legalscd_nginx` est joignable par le reseau `comparer-changer-network` :
     * pas de sortie Internet, pas de negociation TLS, quelques millisecondes au
     * lieu de quelques dizaines. C'est ce que font les autres sites du parc
     * (cf. `template.comparer-changer.fr/public/legals.php`).
     *
     * L'URL publique reste le repli : le reseau du parc peut etre indisponible,
     * ou le site tourner ailleurs.
     */
    private function fetch(string $remotePage): ?string
    {
        foreach ($this->hosts() as $base) {
            $body = $this->fetchFrom($base, $remotePage);
            if ($body !== null) {
                return $body;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function hosts(): array
    {
        $hosts = [];
        foreach (['LEGALS_INTERNAL_URL', 'LEGALS_BASE_URL'] as $key) {
            $value = rtrim((string) $this->config->get($key, ''), '/');
            if ($value !== '') {
                $hosts[] = $value;
            }
        }
        return $hosts;
    }

    private function fetchFrom(string $base, string $remotePage): ?string
    {
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
            // Journalise en information et non en avertissement : l'echec de
            // l'hote interne est attendu hors du reseau du parc, et le repli
            // suit immediatement. Seule l'absence totale de contenu, tracee
            // par fragment(), est un vrai probleme.
            $this->logger->info('Contenu legal : hote injoignable, repli', [
                'host' => $base,
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
