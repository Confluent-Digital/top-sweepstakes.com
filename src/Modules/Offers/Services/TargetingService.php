<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

/**
 * Decide si un participant correspond aux regles de ciblage d'une offre.
 *
 * Un seul endroit, un seul comportement, un booleen en sortie. Dans
 * meilleursconcours.com la meme boucle de ciblage est recopiee six fois dans
 * un controleur de 2 866 lignes, le service de ciblage fait des `echo` en
 * plein rendu web, et deux de ses branches ne retournent rien du tout.
 *
 * Principe retenu : **fail-closed**. Une regle qu'on ne sait pas interpreter
 * ecarte l'offre. L'implementation historique fait l'inverse : elle laisse
 * passer, si bien qu'une regle mal saisie diffuse l'offre a tout le monde —
 * exactement ce qu'un annonceur ne veut pas, et ce qui se decouvre quand il
 * refuse de payer.
 */
final class TargetingService
{
    /** Parametres sur lesquels une comparaison numerique a un sens. */
    private const NUMERIC_PARAMS = ['dob', 'zip'];

    /**
     * @param list<array<string,mixed>> $rules   lignes `t_offer_targeting`
     * @param array<string,mixed>       $context ['state','zip','dob','gender','phone','email','subid']
     */
    public function matches(array $rules, array $context): bool
    {
        foreach ($rules as $rule) {
            if (!$this->matchesRule($rule, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     */
    private function matchesRule(array $rule, array $context): bool
    {
        $param = (string) ($rule['offer_targeting_param'] ?? '');
        $operator = (string) ($rule['offer_targeting_operator'] ?? '');
        $expected = (string) ($rule['offer_targeting_value'] ?? '');

        $actual = $this->resolve($param, $context);
        if ($actual === null) {
            // Le participant n'a pas renseigne ce champ : une regle qui porte
            // dessus ne peut pas etre satisfaite. Seul `not_empty` a une
            // reponse claire — et c'est « non ».
            return false;
        }

        return match ($operator) {
            'not_empty' => $actual !== '',
            'in' => in_array($this->normalize($actual), $this->listOf($expected), true),
            'not_in' => !in_array($this->normalize($actual), $this->listOf($expected), true),
            'gt', 'gte', 'lt', 'lte' => $this->compareNumeric($param, $operator, $actual, $expected),
            'between' => $this->between($param, $actual, $expected),
            'regex' => $this->regex($actual, $expected),
            default => false,
        };
    }

    /**
     * Valeur du participant pour ce parametre, ou null si le parametre n'est
     * pas reconnu.
     *
     * @param array<string,mixed> $context
     */
    private function resolve(string $param, array $context): ?string
    {
        return match ($param) {
            'state', 'zip', 'gender', 'phone', 'subid' => trim((string) ($context[$param] ?? '')),
            'dob' => trim((string) ($context['dob'] ?? '')),
            'email_domain' => $this->emailDomain((string) ($context['email'] ?? '')),
            default => null,
        };
    }

    private function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    /**
     * Sur `dob`, les comparaisons portent sur l'AGE et non sur la date : une
     * regle « gt 18 » se lit « plus de 18 ans », ce qui est le sens metier
     * attendu. Sur `zip`, elles portent sur la valeur numerique du code postal.
     * Sur tout autre parametre, une comparaison numerique n'a pas de sens :
     * l'offre est ecartee plutot que d'inventer une interpretation.
     */
    private function compareNumeric(string $param, string $operator, string $actual, string $expected): bool
    {
        if (!in_array($param, self::NUMERIC_PARAMS, true) || !is_numeric(trim($expected))) {
            return false;
        }

        $left = $this->numericValue($param, $actual);
        if ($left === null) {
            return false;
        }
        $right = (float) trim($expected);

        return match ($operator) {
            'gt' => $left > $right,
            'gte' => $left >= $right,
            'lt' => $left < $right,
            'lte' => $left <= $right,
            default => false,
        };
    }

    private function between(string $param, string $actual, string $expected): bool
    {
        $bounds = $this->listOf($expected);
        if (count($bounds) !== 2 || !is_numeric($bounds[0]) || !is_numeric($bounds[1])) {
            return false;
        }
        if (!in_array($param, self::NUMERIC_PARAMS, true)) {
            return false;
        }

        $value = $this->numericValue($param, $actual);
        if ($value === null) {
            return false;
        }

        $low = min((float) $bounds[0], (float) $bounds[1]);
        $high = max((float) $bounds[0], (float) $bounds[1]);
        return $value >= $low && $value <= $high;
    }

    private function numericValue(string $param, string $actual): ?float
    {
        if ($param === 'dob') {
            $age = $this->age($actual);
            return $age === null ? null : (float) $age;
        }
        // Code postal : les 5 premiers chiffres, ZIP+4 tolere.
        if (!preg_match('/^\d{1,5}/', $actual, $m)) {
            return null;
        }
        return (float) $m[0];
    }

    private function age(string $dob): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if ($date === false) {
            return null;
        }
        $now = new \DateTimeImmutable('today');
        if ($date > $now) {
            return null;
        }
        return (int) $date->diff($now)->y;
    }

    /**
     * La regle vient de la base : elle est ecrite par un operateur, pas par un
     * participant, mais elle reste une entree. Le motif est ancre et compile
     * sous suppression d'erreur ; un motif invalide ecarte l'offre au lieu de
     * remonter un warning PHP en pleine page.
     */
    private function regex(string $actual, string $pattern): bool
    {
        if (trim($pattern) === '') {
            return false;
        }
        $delimited = '/' . str_replace('/', '\/', $pattern) . '/u';
        $result = @preg_match($delimited, $actual);
        return $result === 1;
    }

    /** @return list<string> */
    private function listOf(string $raw): array
    {
        $parts = array_map(
            fn(string $v): string => $this->normalize($v),
            explode(',', $raw)
        );
        return array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
