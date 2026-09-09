<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use App\Core\Config;

/**
 * Textes de consentement proposes sur le formulaire.
 *
 * SOURCE UNIQUE : le gabarit affiche exactement la chaine que cette classe
 * produit, et c'est cette meme chaine qui est archivee dans `t_lead_consent`.
 * Rien ne doit pouvoir diverger entre ce qui est montre et ce qui est prouve.
 *
 * Sans cela, on retombe sur le probleme de meilleursconcours.com : le texte du
 * consentement n'existe qu'en gabarit, versionne en git et modifie au fil du
 * temps ; reconstituer ce qu'un participant a accepte un jour donne y est
 * impossible. Voir .claude/rules/legal-us.md.
 */
final class ConsentCatalog
{
    public const RULES = 'rules';
    public const MARKETING_EMAIL = 'marketing_email';
    public const TCPA_PHONE = 'tcpa_phone';

    public function __construct(private Config $config)
    {
    }

    /**
     * Consentements a presenter pour ce concours, dans l'ordre d'affichage.
     *
     * Chaque entree porte son texte complet et son caractere obligatoire.
     * `tcpa_phone` n'apparait que si le concours collecte le telephone : un
     * consentement au demarchage sans numero n'a pas d'objet.
     *
     * @param array<string,mixed> $sweepstake
     * @param list<string>        $collectedFields
     * @return list<array{type:string, required:bool, text:string}>
     */
    public function forSweepstake(array $sweepstake, array $collectedFields): array
    {
        $sponsor = trim((string) ($sweepstake['sweepstake_sponsor_name'] ?? ''));
        $site = (string) $this->config->get('APP_DOMAIN', 'this website');
        $minAge = (int) ($sweepstake['sweepstake_min_age'] ?? 18);
        $brand = $sponsor !== '' ? $sponsor : $site;

        $consents = [
            [
                'type' => self::RULES,
                'required' => true,
                'text' => sprintf(
                    'I am at least %d years old and a legal resident of the United States, '
                    . 'and I agree to the Official Rules, the Terms of Service and the Privacy Policy of %s.',
                    $minAge,
                    $brand
                ),
            ],
            [
                'type' => self::MARKETING_EMAIL,
                'required' => false,
                'text' => sprintf(
                    'I agree to receive marketing and promotional emails from %s and its marketing partners. '
                    . 'I understand I can unsubscribe at any time using the link in any email.',
                    $brand
                ),
            ],
        ];

        // TCPA : consentement ecrit, expres et DISTINCT. Il ne se deduit ni de
        // l'acceptation des regles, ni de l'opt-in e-mail, ni du simple fait
        // d'avoir renseigne un numero.
        if (in_array('phone', $collectedFields, true)) {
            $consents[] = [
                'type' => self::TCPA_PHONE,
                'required' => false,
                'text' => sprintf(
                    'I expressly consent to receive telephone calls and text messages from %s and its '
                    . 'marketing partners at the number I provided, including calls and messages placed '
                    . 'using an automatic telephone dialing system or a prerecorded voice. Consent is not '
                    . 'a condition of entry or of any purchase. Message and data rates may apply. '
                    . 'I can revoke this consent at any time by replying STOP.',
                    $brand
                ),
            ];
        }

        return $consents;
    }

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
