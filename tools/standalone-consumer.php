<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Componenta\Http\Middleware\Legacy\DoublePassAdapter;
use Componenta\Http\Middleware\Legacy\SinglePassAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require $argv[1];

foreach ([
    'componenta/middleware-factory',
    'componenta/pipeline',
    'componenta/di',
    'componenta/config',
    'pestphp/pest',
    'phpbench/phpbench',
    'phpstan/phpstan',
] as $package) {
    if (InstalledVersions::isInstalled($package)) {
        throw new RuntimeException("Unexpected runtime dependency: $package");
    }
}

$next = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(202, [], $request->getUri()->getPath());
    }
};
$single = new SinglePassAdapter(
    static fn (ServerRequestInterface $request, callable $next): ResponseInterface =>
        $next($request)->withHeader('X-Adapter', 'single'),
);
$double = new DoublePassAdapter(
    static fn (ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface =>
        $request->getMethod() === 'OPTIONS' ? $response->withStatus(204) : $next($request)->withHeader('X-Adapter', 'double'),
    new Psr17Factory(),
);

foreach (['single' => $single, 'double' => $double] as $name => $middleware) {
    $response = $middleware->process(new ServerRequest('GET', '/standalone'), $next);
    if ($response->getStatusCode() !== 202
        || (string) $response->getBody() !== '/standalone'
        || $response->getHeaderLine('X-Adapter') !== $name
    ) {
        throw new RuntimeException("Standalone $name adapter failed.");
    }
}
if ($double->process(new ServerRequest('OPTIONS', '/'), $next)->getStatusCode() !== 204) {
    throw new RuntimeException('Double-pass seed response was not usable.');
}

echo 'Standalone runtime-only installation passed with authoritative Composer classmap.', PHP_EOL;
