<?php

declare(strict_types=1);

namespace App\Modules\Legal\Controllers;

use App\Modules\Leads\Models\Repositories\SuppressionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Desinscription (CAN-SPAM) et demandes CCPA/CPRA.
 *
 * Ces pages ne sont pas decoratives : le lien « Do Not Sell or Share My
 * Personal Information » doit exister sur chaque page ET aboutir a un
 * traitement effectif, et une desinscription doit etre honoree.
 * Voir .claude/rules/legal-us.md.
 */
final class ComplianceController
{
    /** Types de demande acceptes par la page CCPA. */
    private const CCPA_REQUESTS = ['do_not_sell', 'delete_request'];

    public function __construct(
        private Twig $view,
        private SuppressionRepository $suppressions,
    ) {
    }

    public function unsubscribe(Request $request, Response $response): Response
    {
        return $this->handle(
            $request,
            $response,
            'front/unsubscribe.html.twig',
            'unsubscribe',
            'Unsubscribe',
            'You have been unsubscribed. Please allow up to 10 business days for the change '
            . 'to take effect across all of our mailings.'
        );
    }

    public function doNotSell(Request $request, Response $response): Response
    {
        $input = (array) $request->getParsedBody();
        $type = (string) ($input['request_type'] ?? 'do_not_sell');

        return $this->handle(
            $request,
            $response,
            'front/do-not-sell.html.twig',
            in_array($type, self::CCPA_REQUESTS, true) ? $type : 'do_not_sell',
            'Do Not Sell or Share My Personal Information',
            'Your request has been recorded. We will process it within the timeframe required by law.'
        );
    }

    private function handle(
        Request $request,
        Response $response,
        string $template,
        string $type,
        string $title,
        string $successMessage,
    ): Response {
        $email = '';
        $error = null;
        $done = false;

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $email = trim((string) ($input['email'] ?? ''));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $error = 'Please enter a valid email address.';
            } else {
                // Seule l'empreinte est conservee : la liste de suppression n'a
                // pas besoin de l'adresse en clair pour faire son office, et
                // une liste en clair serait une base de donnees personnelles de
                // plus a proteger et a conserver sans limite de duree.
                $this->suppressions->add(md5(strtolower($email)), '', $type, 'web_form');
                $done = true;
            }
        }

        return $this->view->render($response, $template, [
            'title' => $title,
            'email' => $email,
            'error' => $error,
            'done' => $done,
            'success_message' => $successMessage,
        ]);
    }
}
