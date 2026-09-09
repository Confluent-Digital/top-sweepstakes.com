<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/** Verifie le jeton CSRF (champ `_csrf`) sur les methodes mutatives du back-office. */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body['_csrf'] ?? null) : null;
            if (!Csrf::check(is_string($token) ? $token : null)) {
                $response = (new ResponseFactory())->createResponse(419);
                $response->getBody()->write('Jeton CSRF invalide ou expire.');
                return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
        }
        return $handler->handle($request);
    }
}
