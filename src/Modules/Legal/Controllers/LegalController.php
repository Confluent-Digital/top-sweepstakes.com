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

    /**
     * Fragment seul, sans gabarit : c'est ce que charge la popin.
     *
     * La page complete (`show`) reste servie a la meme adresse sans ce suffixe.
     * Les deux sont necessaires : la popin evite de faire quitter le tunnel a
     * un participant en cours de saisie, et la page reste le repli quand le
     * JavaScript ne s'execute pas. Une mention legale inaccessible parce qu'un
     * script a echoue serait une non-conformite.
     *
     * @param array<string,string> $args
     */
    public function fragment(Request $request, Response $response, array $args): Response
    {
        $page = (string) $args['page'];
        $content = $this->legal->fragment($page);

        if ($content === null) {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
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
