<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tasks;

use App\Modules\Admin\Models\Repositories\AdminUserRepository;

/**
 * Creation d'un compte de back-office, en ligne de commande.
 *
 * Il n'existe pas d'ecran d'inscription : le premier compte se cree ici, et les
 * suivants aussi. Une page publique de creation de compte administrateur serait
 * la premiere chose qu'un robot trouverait.
 */
final class CreateAdminUserTask
{
    public const MIN_PASSWORD = 12;

    /** Variable d'environnement lue quand aucun terminal n'est disponible. */
    public const ENV_PASSWORD = 'ADMIN_PASSWORD';

    /** Raison du dernier echec de saisie, pour ne pas rendre un message faux. */
    private ?string $echec = null;

    public function __construct(private AdminUserRepository $users)
    {
    }

    /**
     * Mot de passe, par ordre de preference decroissante en matiere de fuite.
     *
     * 1. **Saisie interactive**, sans echo. Rien ne transite par la ligne de
     *    commande.
     * 2. **Variable d'environnement**, pour les installations scriptees. Visible
     *    dans /proc/<pid>/environ du seul processus, pas dans `ps`.
     * 3. **--password**, conserve pour ne pas casser l'existant, mais c'est le
     *    pire des trois : l'argument apparait dans l'historique du shell ET
     *    dans la liste des processus, ou n'importe quel utilisateur de la
     *    machine peut le lire le temps de l'execution. Un avertissement le dit.
     */
    private function password(array $options): ?string
    {
        if (isset($options['password']) && $options['password'] !== '') {
            fwrite(STDERR, "!! --password apparait dans l'historique du shell et dans `ps`.\n"
                . "   Preferer la saisie interactive (omettre --password) ou "
                . self::ENV_PASSWORD . ".\n");
            return (string) $options['password'];
        }

        $fromEnv = getenv(self::ENV_PASSWORD);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return $this->prompt();
    }

    /**
     * Saisie masquee.
     *
     * `stty -echo` plutot qu'une extension : readline n'est pas garantie dans
     * l'image du parc. La restauration passe par un `finally` — un terminal
     * laisse sans echo apres une interruption est desagreable a recuperer.
     */
    private function prompt(): ?string
    {
        if (!stream_isatty(STDIN)) {
            $this->echec = 'Mot de passe non fourni. Sans terminal interactif, passer par '
                . self::ENV_PASSWORD . '.';
            return null;
        }

        $etat = trim((string) shell_exec('stty -g 2>/dev/null'));
        try {
            if ($etat !== '') {
                shell_exec('stty -echo');
            }
            // L'echo reste coupe pour les DEUX saisies. Le retablir entre les
            // deux laissait la confirmation s'afficher en clair a l'ecran.
            fwrite(STDOUT, 'Mot de passe (' . self::MIN_PASSWORD . " caracteres minimum) : ");
            $saisi = fgets(STDIN);
            fwrite(STDOUT, "\n");

            fwrite(STDOUT, 'Confirmation : ');
            $confirme = fgets(STDIN);
            fwrite(STDOUT, "\n");
        } finally {
            if ($etat !== '') {
                shell_exec('stty ' . escapeshellarg($etat));
            }
        }

        $saisi = $saisi === false ? '' : trim($saisi, "\r\n");
        $confirme = $confirme === false ? '' : trim($confirme, "\r\n");

        if ($saisi === '') {
            $this->echec = 'Aucun mot de passe saisi.';
            return null;
        }
        if ($saisi !== $confirme) {
            $this->echec = 'Les deux saisies different.';
            return null;
        }
        return $saisi;
    }

    /**
     * @param array<string,string> $options
     * @return array{ok: bool, message: string}
     */
    public function run(array $options): array
    {
        $email = trim($options['email'] ?? '');
        $name = trim($options['name'] ?? '');
        $role = $options['role'] ?? 'admin';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => '--email est obligatoire et doit etre une adresse valide.'];
        }

        $password = $this->password($options);
        if ($password === null) {
            return ['ok' => false, 'message' => $this->echec ?? 'Mot de passe non fourni.'];
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            return [
                'ok' => false,
                'message' => sprintf('Le mot de passe doit faire au moins %d caracteres.', self::MIN_PASSWORD),
            ];
        }
        if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
            return ['ok' => false, 'message' => '--role doit valoir admin, operator ou viewer.'];
        }
        if ($this->users->findByEmail($email) !== null) {
            return ['ok' => false, 'message' => 'Un compte existe deja pour ' . $email . '.'];
        }

        $id = $this->users->create($email, $name !== '' ? $name : $email, $password, $role);

        return ['ok' => true, 'message' => sprintf('Compte %s cree (#%d, role %s).', $email, $id, $role)];
    }
}
