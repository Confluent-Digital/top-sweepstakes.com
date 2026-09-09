<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use DateTimeImmutable;

/**
 * Validation des donnees de participation, au format americain.
 *
 * La validation cote serveur fait autorite : le cote client n'est qu'un
 * confort. Deux controles portent une consequence juridique et pas seulement
 * ergonomique — l'age minimum et les Etats exclus. Ils refusent explicitement,
 * jamais en silence : un participant ecarte doit savoir pourquoi, et un
 * participant ineligible ne doit pas figurer dans la base.
 */
final class LeadValidator
{
    /** Champs connus du formulaire. */
    public const FIELDS = [
        'email', 'first_name', 'last_name', 'address', 'city',
        'state', 'zip', 'phone', 'dob', 'gender',
    ];

    /**
     * @param array<string,mixed>       $input      donnees soumises
     * @param list<array<string,mixed>> $fields     lignes `t_sweepstake_field` de l'etape
     * @param array<string,mixed>       $sweepstake ligne `t_sweepstake`
     * @return array{values: array<string,string>, errors: array<string,string>}
     */
    public function validate(array $input, array $fields, array $sweepstake): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = (string) ($field['sweepstake_field_key'] ?? '');
            if (!in_array($key, self::FIELDS, true)) {
                continue;
            }

            $raw = trim((string) ($input[$key] ?? ''));
            $required = (bool) ($field['sweepstake_field_required'] ?? true);

            if ($raw === '') {
                if ($required) {
                    $errors[$key] = $this->requiredMessage($key);
                }
                $values[$key] = '';
                continue;
            }

            $error = $this->validateField($key, $raw, $sweepstake);
            if ($error !== null) {
                $errors[$key] = $error;
            }
            $values[$key] = $this->normalizeField($key, $raw);
        }

        // L'eligibilite geographique se verifie une fois l'Etat normalise, et
        // seulement s'il est valide : inutile d'empiler deux messages.
        if (isset($values['state']) && $values['state'] !== '' && !isset($errors['state'])) {
            $excluded = UsStates::parseExcluded((string) ($sweepstake['sweepstake_excluded_states'] ?? ''));
            if (in_array($values['state'], $excluded, true)) {
                $errors['state'] = sprintf(
                    'We\'re sorry — this sweepstake is not open to residents of %s.',
                    UsStates::name($values['state']) ?? $values['state']
                );
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** @param array<string,mixed> $sweepstake */
    private function validateField(string $key, string $value, array $sweepstake): ?string
    {
        return match ($key) {
            'email' => $this->validateEmail($value),
            'first_name', 'last_name' => mb_strlen($value) < 2
                ? 'Please enter at least 2 characters.'
                : null,
            'address' => mb_strlen($value) < 3 ? 'Please enter your street address.' : null,
            'city' => mb_strlen($value) < 2 ? 'Please enter your city.' : null,
            'state' => UsStates::isValid($value) ? null : 'Please select a valid U.S. state.',
            'zip' => preg_match('/^\d{5}(-\d{4})?$/', $value) === 1
                ? null
                : 'Please enter a valid 5-digit ZIP code.',
            'phone' => $this->validatePhone($value),
            'dob' => $this->validateDob($value, (int) ($sweepstake['sweepstake_min_age'] ?? 18)),
            'gender' => in_array(strtolower($value), ['male', 'female', 'other', 'unknown'], true)
                ? null
                : 'Please select a valid option.',
            default => null,
        };
    }

    private function validateEmail(string $value): ?string
    {
        if (mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'Please enter a valid email address.';
        }
        return null;
    }

    /**
     * Numero nord-americain (NANP) : dix chiffres, l'indicatif regional et le
     * prefixe d'echange ne commencant ni par 0 ni par 1. Un « 1 » de tete est
     * accepte puis retire.
     */
    private function validatePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) !== 1) {
            return 'Please enter a valid 10-digit U.S. phone number.';
        }
        return null;
    }

    /**
     * L'age minimum n'est pas une regle de confort : il figure dans les
     * Official Rules et conditionne l'eligibilite.
     */
    private function validateDob(string $value, int $minAge): ?string
    {
        $date = $this->parseDob($value);
        if ($date === null) {
            return 'Please enter a valid date of birth.';
        }

        $today = new DateTimeImmutable('today');
        if ($date >= $today) {
            return 'Please enter a valid date of birth.';
        }
        if ((int) $date->diff($today)->y < $minAge) {
            return sprintf('You must be at least %d years old to enter.', $minAge);
        }
        return null;
    }

    /** Accepte les deux saisies courantes : le format ISO et le format americain. */
    private function parseDob(string $value): ?DateTimeImmutable
    {
        foreach (['!Y-m-d', '!m/d/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format($format === '!Y-m-d' ? 'Y-m-d' : 'm/d/Y') === $value) {
                return $date;
            }
        }
        return null;
    }

    private function normalizeField(string $key, string $value): string
    {
        return match ($key) {
            'email' => strtolower($value),
            'state' => strtoupper($value),
            'gender' => strtolower($value),
            'phone' => $this->normalizePhone($value),
            'dob' => $this->parseDob($value)?->format('Y-m-d') ?? '',
            default => $value,
        };
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    private function requiredMessage(string $key): string
    {
        return match ($key) {
            'email' => 'Please enter your email address.',
            'first_name' => 'Please enter your first name.',
            'last_name' => 'Please enter your last name.',
            'address' => 'Please enter your street address.',
            'city' => 'Please enter your city.',
            'state' => 'Please select your state.',
            'zip' => 'Please enter your ZIP code.',
            'phone' => 'Please enter your phone number.',
            'dob' => 'Please enter your date of birth.',
            'gender' => 'Please select an option.',
            default => 'This field is required.',
        };
    }
}
