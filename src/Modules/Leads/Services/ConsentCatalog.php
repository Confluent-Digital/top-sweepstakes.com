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
 *
 * Les LIENS ne font pas exception a cette regle. Le texte archive reste du
 * texte brut ; `html()` ne fait qu'y poser des ancres, sur des libelles qui y
 * figurent deja mot pour mot. Les mots affiches et les mots archives sont donc
 * les memes — seule la mise en forme differe.
 *
 * ⚠️ AUCUN consentement telephonique marketing n'est collecte, et ce n'est pas
 * un oubli. Le numero sert a l'administration du jeu, a la verification
 * d'eligibilite, a la prevention de la fraude et au contact du gagnant — rien
 * d'autre. Ne pas reintroduire de case TCPA sans decision juridique ecrite :
 * une case cochee cree une obligation de preuve, et une preuve mal formee vaut
 * moins que pas de preuve du tout.
 */
final class ConsentCatalog
{
    public const RULES = 'rules';
    public const MARKETING_EMAIL = 'marketing_email';

    /**
     * Type historique, conserve pour la LECTURE des preuves deja archivees.
     *
     * `t_lead_consent` est immuable : les consentements telephoniques recueillis
     * avant ce changement restent en base et doivent rester lisibles. Il n'est
     * plus jamais propose.
     */
    public const TCPA_PHONE = 'tcpa_phone';

    /** Version des textes, archivee avec chaque preuve. */
    public const VERSION = '2026-09-15';

    public function __construct(private Config $config)
    {
    }

    /**
     * Consentements a presenter pour ce concours, dans l'ordre d'affichage.
     *
     * @param array<string,mixed> $sweepstake
     * @param list<string>        $collectedFields
     * @return list<array{type:string, required:bool, flag:string, text:string, links:array<string,string>}>
     */
    public function forSweepstake(array $sweepstake, array $collectedFields): array
    {
        $sponsor = trim((string) ($sweepstake['sweepstake_sponsor_name'] ?? ''));
        $marque = $sponsor !== '' ? $sponsor : (string) $this->config->get('APP_DOMAIN', 'this website');
        $slug = (string) ($sweepstake['sweepstake_slug'] ?? '');
        $minAge = (int) ($sweepstake['sweepstake_min_age'] ?? 18);

        return [
            [
                'type' => self::RULES,
                'required' => true,
                'flag' => 'Required',
                'text' => sprintf(
                    'I confirm that I am at least %d years old, have reached the age of majority in my '
                    . 'state of residence, and am a legal resident of an eligible U.S. jurisdiction. '
                    . 'I have read and agree to the Official Rules and acknowledge that I have read the '
                    . 'Sweepstakes Privacy Notice – United States.',
                    $minAge
                ),
                'links' => [
                    'Official Rules' => '/' . $slug . '/rules',
                    'Sweepstakes Privacy Notice – United States' => '/sweepstakes-privacy',
                ],
            ],
            [
                'type' => self::MARKETING_EMAIL,
                'required' => false,
                'flag' => 'Optional — email marketing',
                // Cette case n'autorise QUE l'editeur. L'adresse n'est pas
                // transmise a un partenaire pour qu'il demarche lui-meme : la
                // phrase l'ecrit, et le traitement doit s'y tenir.
                'text' => sprintf(
                    'I agree to receive marketing and promotional emails from %s, including emails '
                    . 'containing offers relating to third-party products and services. Consent is not a '
                    . 'condition of entering or winning this Sweepstakes or purchasing any goods or '
                    . 'services. I may unsubscribe at any time using the unsubscribe link contained in any '
                    . 'marketing email or through our Your Privacy Choices page.',
                    $marque
                ),
                'links' => [
                    'Your Privacy Choices' => '/privacy-choices',
                ],
            ],
        ];
    }

    /**
     * Texte du consentement, avec ses liens, pret a afficher.
     *
     * Le texte est echappe D'ABORD, les ancres posees ENSUITE : l'inverse
     * laisserait passer du balisage venu d'une valeur de configuration.
     *
     * @param array{text:string, links?:array<string,string>} $consent
     */
    public static function html(array $consent): string
    {
        $html = htmlspecialchars($consent['text'], ENT_QUOTES, 'UTF-8');

        foreach ($consent['links'] ?? [] as $libelle => $url) {
            $cherche = htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8');
            $ancre = sprintf(
                '<a href="%s" data-legal-inline>%s</a>',
                htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
                $cherche
            );
            // Une seule occurrence : le libelle peut reapparaitre plus loin
            // dans une phrase sans devoir etre un lien.
            $position = strpos($html, $cherche);
            if ($position !== false) {
                $html = substr_replace($html, $ancre, $position, strlen($cherche));
            }
        }

        return $html;
    }

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
