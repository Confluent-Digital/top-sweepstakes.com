<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

/**
 * Filtre anti-robot et anti-saisie bidon, adapte au marche americain.
 *
 * Le formulaire est public et recoit du trafic hostile en continu. Trois
 * signaux independants, du moins couteux au plus couteux :
 *
 *  1. le champ piege, qu'un humain ne voit pas ;
 *  2. la vitesse de soumission, qu'un humain ne peut pas atteindre ;
 *  3. le contenu lui-meme (numeros impossibles, mots interdits).
 *
 * Le filtre reste volontairement conservateur : refuser un vrai participant
 * coute plus cher qu'accepter un faux, puisque le trafic est achete.
 */
final class SpamGuard
{
    /** Nom du champ piege. Il ne doit rien evoquer d'evitable pour un robot. */
    public const HONEYPOT_FIELD = 'website';

    /** Champ portant l'horodatage d'ouverture du formulaire. */
    public const TIMESTAMP_FIELD = 'form_opened_at';

    /**
     * Un formulaire rempli en moins de trois secondes ne l'a pas ete au clavier.
     * Le seuil est bas a dessein : mieux vaut laisser passer un robot rapide
     * qu'ecarter un participant qui colle ses donnees.
     */
    private const MIN_SECONDS = 3;

    /** Un onglet reste ouvert longtemps : au-dela, l'horodatage n'apprend rien. */
    private const MAX_SECONDS = 7200;

    /**
     * Indicatifs impossibles dans le plan de numerotation nord-americain, en
     * plus de ceux qu'ecarte deja LeadValidator.
     *
     * @var list<string>
     */
    private const FAKE_AREA_CODES = ['555', '000', '111', '123', '999'];

    /**
     * Parties locales d'adresse et noms qui n'appartiennent jamais a un vrai
     * participant.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'test', 'testing', 'asdf', 'asdfasdf', 'qwerty', 'azerty',
        'aaaa', 'bbbb', 'xxxx', 'zzzz', 'abcd', '1234',
        'fake', 'nobody', 'noone', 'anonymous', 'unknown',
        'spam', 'junk', 'trash', 'donotreply', 'no-reply', 'noreply',
        'admin', 'root', 'webmaster', 'postmaster',
    ];

    /**
     * @param array<string,mixed> $input   donnees brutes soumises
     * @param array<string,string> $values donnees validees et normalisees
     * @return string|null raison du rejet, ou null si la soumission passe
     */
    public function reject(array $input, array $values): ?string
    {
        if ($this->honeypotFilled($input)) {
            return 'honeypot';
        }
        if ($this->tooFast($input)) {
            return 'too_fast';
        }
        if ($this->fakePhone($values['phone'] ?? '')) {
            return 'fake_phone';
        }
        if ($this->stopWord($values)) {
            return 'stop_word';
        }
        return null;
    }

    /** @param array<string,mixed> $input */
    private function honeypotFilled(array $input): bool
    {
        $value = $input[self::HONEYPOT_FIELD] ?? '';
        return !is_array($value) && trim((string) $value) !== '';
    }

    /** @param array<string,mixed> $input */
    private function tooFast(array $input): bool
    {
        $opened = (int) ($input[self::TIMESTAMP_FIELD] ?? 0);
        if ($opened <= 0) {
            // Horodatage absent : on ne conclut rien. Il peut manquer pour de
            // bonnes raisons (JavaScript desactive, page mise en cache).
            return false;
        }

        $elapsed = time() - $opened;
        if ($elapsed > self::MAX_SECONDS) {
            return false;
        }
        return $elapsed < self::MIN_SECONDS;
    }

    /**
     * Numeros qu'aucun operateur n'attribue : indicatif de fiction, ou meme
     * chiffre repete quatre fois de suite.
     */
    private function fakePhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return false;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10) {
            return false;
        }

        if (in_array(substr($digits, 0, 3), self::FAKE_AREA_CODES, true)) {
            return true;
        }
        // 5551234567 : indicatif d'echange reserve a la fiction.
        if (substr($digits, 3, 3) === '555') {
            return true;
        }
        // Sept chiffres identiques consecutifs. Le seuil est haut a dessein :
        // a quatre, comme dans meilleursconcours.com, « 2125556666 » serait
        // ecarte alors que c'est un numero plausible.
        return preg_match('/(\d)\1{6,}/', $digits) === 1;
    }

    /** @param array<string,string> $values */
    private function stopWord(array $values): bool
    {
        $candidates = [
            strtolower(trim($values['first_name'] ?? '')),
            strtolower(trim($values['last_name'] ?? '')),
            $this->emailLocalPart($values['email'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            // Comparaison exacte, pas « contient » : « Sandra » contient
            // « and », et « Testa » est un vrai patronyme.
            if (in_array($candidate, self::STOP_WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    private function emailLocalPart(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return '';
        }
        // On retire le suffixe « +quelquechose », courant et legitime.
        $local = strtolower(substr($email, 0, $at));
        $plus = strpos($local, '+');
        return $plus === false ? $local : substr($local, 0, $plus);
    }
}
