<?php

declare(strict_types=1);

namespace App\Modules\Legal\Controllers;

use App\Modules\Legal\Services\LegalContentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

final class LegalController
{
    /** Titres affiches, cote public. */
    private const TITLES = [
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms of Service',
        'legal' => 'Legal Notice',
        'partners' => 'Our Marketing Partners',
        'cookies' => 'Cookie Policy',
    ];

    public function __construct(
        private Twig $view,
        private LegalContentService $legal,
    ) {
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $page = (string) $args['page'];
        $fragment = $this->legal->fragment($page);

        if ($fragment === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, 'front/legal.html.twig', [
            'title' => self::TITLES[$page] ?? 'Legal',
            'content' => $fragment,
        ]);
    }
}
