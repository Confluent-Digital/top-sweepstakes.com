<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use App\Modules\Leads\Models\Repositories\ConsentRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Archive la preuve de consentement.
 *
 * Une ligne par consentement PRESENTE, qu'il ait ete accorde ou non : un refus
 * explicite est une information aussi utile qu'un accord — c'est lui qui prouve
 * qu'on n'a pas coche la case a la place du participant.
 */
final class ConsentRecorder
{
    public function __construct(private ConsentRepository $repository)
    {
    }

    /**
     * @param list<array{type:string, required:bool, text:string}> $presented
     * @param array<string,mixed>                                  $input
     * @return list<int>
     */
    public function record(
        int $leadId,
        int $sweepstakeId,
        array $presented,
        array $input,
        ServerRequestInterface $request,
    ): array {
        $ip = $this->clientIp($request);
        $userAgent = mb_substr($request->getHeaderLine('User-Agent'), 0, 500);
        $url = mb_substr((string) $request->getUri(), 0, 500);

        $ids = [];
        foreach ($presented as $consent) {
            $granted = $this->isGranted($input, $consent['type']);
            $ids[] = $this->repository->record([
                'lead_consent_id_lead' => $leadId,
                'lead_consent_id_sweepstake' => $sweepstakeId,
                'lead_consent_type' => $consent['type'],
                'lead_consent_granted' => $granted ? 1 : 0,
                // Le texte reellement affiche, pas une reference vers un gabarit.
                'lead_consent_text' => $consent['text'],
                'lead_consent_text_hash' => ConsentCatalog::hash($consent['text']),
                'lead_consent_page_url' => $url,
                'lead_consent_ip' => $ip,
                'lead_consent_user_agent' => $userAgent,
            ]);
        }
        return $ids;
    }

    /** @param array<string,mixed> $input */
    private function isGranted(array $input, string $type): bool
    {
        $value = $input['consent_' . $type] ?? null;
        if (is_array($value)) {
            return false;
        }
        return in_array((string) $value, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * IP du visiteur, telle qu'elle doit figurer sur la preuve.
     *
     * nginx tourne devant PHP-FPM : REMOTE_ADDR est alors l'adresse du proxy.
     * On lit l'en-tete pose par notre propre proxy, en ne retenant que la
     * premiere adresse de la chaine, puis on valide le format — un en-tete
     * client est falsifiable, une valeur non conforme ne doit pas atterrir
     * dans une preuve.
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        foreach (['X-Real-IP', 'X-Forwarded-For'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value === '') {
                continue;
            }
            $candidate = trim(explode(',', $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $server = $request->getServerParams();
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
    }
}
