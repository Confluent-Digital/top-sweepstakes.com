<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * En-tetes de securite sur toutes les reponses.
 *
 * Pas de Content-Security-Policy globale ici : les pages publiques embarquent
 * des pixels de regie et de media buy dont les domaines sont pilotes en base,
 * une CSP figee dans le code les casserait en silence. Elle sera posee sur le
 * groupe /admin quand le back-office existera.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
