<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Config;
use App\Core\Session\SessionStore;
use App\Modules\Admin\Models\Repositories\AdminUserRepository;
use App\Modules\Admin\Services\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Compte de l'utilisateur connecte : double authentification, codes de secours.
 *
 * Accessible a TOUS les roles, y compris « lecture seule » : proteger son
 * propre compte n'est pas une action d'administration, et un lecteur a lui
 * aussi acces aux donnees des participants.
 */
final class AccountController
{
    /** Secret en attente de confirmation, garde en session et non en base. */
    private const PENDING_SECRET = 'admin_totp_pending_secret';

    /** Codes de secours a montrer UNE fois, apres activation ou regeneration. */
    private const SHOW_CODES = 'admin_recovery_codes_once';

    public function __construct(
        private Twig $view,
        private SessionStore $session,
        private AdminUserRepository $users,
        private Config $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $actif = AdminUserRepository::hasTwoFactor($user);

        // Le secret n'est pose en base qu'a la confirmation : une activation
        // abandonnee en cours de route ne doit rien laisser derriere elle.
        $secret = null;
        $uri = null;
        $qr = null;
        if (!$actif) {
            $secret = $this->session->get(self::PENDING_SECRET);
            if (!is_string($secret) || $secret === '') {
                $secret = Totp::generateSecret();
                $this->session->set(self::PENDING_SECRET, $secret);
            }
            $uri = Totp::uri(
                $secret,
                (string) $user['admin_user_email'],
                (string) $this->config->get('APP_NAME', 'Top Sweepstakes')
            );
            $qr = $this->qr($uri);
        }

        // Les codes ne transitent qu'une fois : lus, ils sont retires de la
        // session. Recharger la page ne les reaffiche pas — c'est ce qui force
        // a les mettre de cote tout de suite.
        $codes = $this->session->get(self::SHOW_CODES);
        $this->session->remove(self::SHOW_CODES);

        return $this->view->render($response, 'admin/account.html.twig', [
            'user' => $user,
            'actif' => $actif,
            'secret' => $secret,
            'secret_lisible' => $secret !== null ? Totp::humanize($secret) : null,
            'uri' => $uri,
            'qr' => $qr,
            'codes' => is_array($codes) ? $codes : null,
            'restants' => $actif ? $this->users->countUnusedRecoveryCodes((int) $user['admin_user_id']) : 0,
            'saved' => $request->getQueryParams()['saved'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    /** Active la double authentification, apres verification d'un premier code. */
    public function enable(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $id = (int) $user['admin_user_id'];

        if (AdminUserRepository::hasTwoFactor($user)) {
            return $this->back($response, 'error=deja');
        }

        $secret = $this->session->get(self::PENDING_SECRET);
        if (!is_string($secret) || $secret === '') {
            return $this->back($response, 'error=expire');
        }

        $code = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));
        $periode = Totp::verify($secret, $code);
        if ($periode === null) {
            return $this->back($response, 'error=code');
        }

        $this->users->startTwoFactor($id, $secret);
        $this->users->confirmTwoFactor($id, $periode);
        $this->session->remove(self::PENDING_SECRET);

        // Les codes de secours sont generes A L'ACTIVATION, pas plus tard : un
        // compte protege sans issue de secours est un compte qu'un telephone
        // perdu ferme definitivement.
        $this->session->set(self::SHOW_CODES, $this->users->resetRecoveryCodes($id));
        $this->log($request, 'account.2fa.enable', (string) $id, (string) $user['admin_user_email']);

        return $this->back($response, 'saved=enabled');
    }

    /**
     * Desactive la double authentification.
     *
     * Exige un code valide : sinon une session volee suffirait a retirer la
     * protection, ce qui la reduirait a rien.
     */
    public function disable(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $id = (int) $user['admin_user_id'];

        if (!AdminUserRepository::hasTwoFactor($user)) {
            return $this->back($response, 'error=inactive');
        }

        $code = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));
        if (Totp::verify((string) $user['admin_user_totp_secret'], $code) === null) {
            return $this->back($response, 'error=code');
        }

        $this->users->disableTwoFactor($id);
        $this->log($request, 'account.2fa.disable', (string) $id, (string) $user['admin_user_email']);

        return $this->back($response, 'saved=disabled');
    }

    /** Regenere les codes de secours. Les anciens cessent de valoir. */
    public function recoveryCodes(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $id = (int) $user['admin_user_id'];

        if (!AdminUserRepository::hasTwoFactor($user)) {
            return $this->back($response, 'error=inactive');
        }

        $code = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));
        if (Totp::verify((string) $user['admin_user_totp_secret'], $code) === null) {
            return $this->back($response, 'error=code');
        }

        $this->session->set(self::SHOW_CODES, $this->users->resetRecoveryCodes($id));
        $this->log($request, 'account.2fa.codes', (string) $id, 'codes de secours regeneres');

        return $this->back($response, 'saved=codes');
    }

    /** @return array<string,mixed> */
    private function currentUser(Request $request): array
    {
        $user = $request->getAttribute('admin_user');
        if (!is_array($user)) {
            throw new \RuntimeException('Ecran de compte atteint sans utilisateur authentifie.');
        }
        return $user;
    }

    /** QR en SVG, rendu par le serveur : aucune dependance a un service tiers. */
    private function qr(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));
        return $writer->writeString($uri);
    }

    private function log(Request $request, string $action, string $target, string $detail): void
    {
        $user = $request->getAttribute('admin_user');
        $this->users->log(
            is_array($user) ? (int) $user['admin_user_id'] : null,
            $action,
            $target,
            $detail,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '')
        );
    }

    private function back(Response $response, string $suffix): Response
    {
        return $response->withHeader('Location', '/admin/account?' . $suffix)->withStatus(302);
    }
}
