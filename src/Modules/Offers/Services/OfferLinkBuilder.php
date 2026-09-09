<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use App\Core\Config;

/**
 * Construit l'URL de sortie d'une offre display vers la regie d'affiliation.
 *
 * C'est le SEUL endroit du depot ou cette URL se fabrique. Ni gabarit, ni
 * controleur ne la concatenent : voir .claude/rules/offers-display.md.
 *
 * Deux choses s'y jouent :
 *
 * 1. Le rapprochement des revenus. Le `sid` est la seule cle qui relie notre
 *    tracking a celui de la regie. Son format est positionnel et se lit de
 *    gauche a droite ; il ne se modifie pas a la volee.
 *
 * 2. La protection des donnees personnelles. Chaque parametre est encode, et
 *    seuls les champs listes dans `offer_passthrough_fields` sortent. Sur
 *    meilleursconcours.com, civilite, nom, prenom, email, date de naissance,
 *    adresse, code postal, ville et telephone partent en clair, sans encodage,
 *    vers toutes les offres sans distinction : un prenom contenant « & » casse
 *    l'URL, et l'annonceur recoit des donnees qu'il n'a pas demandees.
 */
final class OfferLinkBuilder
{
    /**
     * Champs transmissibles : cle logique cote base => nom du parametre attendu
     * par la regie. Un champ absent de cette table ne peut pas sortir, meme si
     * quelqu'un l'inscrit dans `offer_passthrough_fields`.
     *
     * @var array<string,string>
     */
    private const FIELD_MAP = [
        'email' => 'email',
        'first_name' => 'firstname',
        'last_name' => 'lastname',
        'address' => 'address',
        'city' => 'city',
        'state' => 'state',
        'zip' => 'zip',
        'phone' => 'phone',
        'dob' => 'dob',
        'gender' => 'gender',
    ];

    public function __construct(private Config $config)
    {
    }

    /**
     * @param array<string,mixed> $offer   ligne `t_offer`
     * @param array<string,mixed> $context ['sweepstake_id', 'subid', 'email_md5', 'lead' => [cle logique => valeur]]
     */
    public function build(array $offer, array $context): string
    {
        $idv = trim((string) ($offer['offer_platform_idv'] ?? ''));
        if ($idv === '') {
            // Une offre sans identifiant de crea ne peut pas etre facturee.
            // OfferSelector l'ecarte en amont ; si on arrive ici, c'est un bug
            // de selection et il doit se voir, pas se propager en silence.
            throw new \RuntimeException(sprintf(
                'Offre %s sans offer_platform_idv : lien de sortie impossible.',
                (string) ($offer['offer_id'] ?? '?')
            ));
        }

        $ids = trim((string) ($offer['offer_platform_ids'] ?? ''));
        if ($ids === '') {
            $ids = (string) $this->config->get('AFFILIATE_SITE_IDS', '');
        }

        $params = [
            'ids' => $ids,
            'idv' => $idv,
            'sid' => $this->buildSid(
                (int) ($context['sweepstake_id'] ?? 0),
                (string) ($context['subid'] ?? ''),
                (string) ($context['email_md5'] ?? ''),
                isset($context['date']) ? (string) $context['date'] : null
            ),
        ];

        foreach ($this->passthroughValues($offer, $context) as $name => $value) {
            $params[$name] = $value;
        }

        // http_build_query encode cle ET valeur : c'est ce qui manque a
        // l'implementation historique.
        return $this->base() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Format positionnel : `{sweepstake_id}_{subid}_{email_md5}_{date}`.
     *
     * La regie et nos rapprochements decoupent sur « _ ». Un subid contenant un
     * underscore decalerait tous les segments suivants et rendrait le
     * rapprochement faux sans qu'aucune erreur ne soit levee : les underscores
     * y sont donc remplaces par des tirets. Un segment vide vaut « - » plutot
     * que rien, pour que le nombre de segments reste constant.
     */
    public function buildSid(int $sweepstakeId, string $subid, string $emailMd5, ?string $date = null): string
    {
        return implode('_', [
            $sweepstakeId > 0 ? (string) $sweepstakeId : '0',
            $this->sanitizeSegment($subid),
            $this->sanitizeSegment($emailMd5),
            $date ?? date('Y-m-d'),
        ]);
    }

    /**
     * Champs personnels effectivement transmis, apres double filtrage :
     * la liste blanche de l'offre, puis la table des champs transmissibles.
     *
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private function passthroughValues(array $offer, array $context): array
    {
        $allowed = $this->decodePassthrough($offer['offer_passthrough_fields'] ?? null);
        if ($allowed === []) {
            return [];
        }

        $lead = is_array($context['lead'] ?? null) ? $context['lead'] : [];
        $out = [];
        foreach ($allowed as $key) {
            if (!isset(self::FIELD_MAP[$key])) {
                continue;
            }
            $value = $lead[$key] ?? null;
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $out[self::FIELD_MAP[$key]] = (string) $value;
        }
        return $out;
    }

    /** @return list<string> */
    private function decodePassthrough(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $decoded)));
    }

    private function sanitizeSegment(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-]/', '-', $value) ?? '';
        return $clean === '' ? '-' : $clean;
    }

    private function base(): string
    {
        $base = $this->config->get('AFFILIATE_TRACKING_BASE');
        if ($base === null) {
            throw new \RuntimeException('AFFILIATE_TRACKING_BASE non configure.');
        }
        return rtrim($base, '?&');
    }
}
