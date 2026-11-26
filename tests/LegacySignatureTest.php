<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Legacy\Tests\Signature;

use Closure;
use Componenta\Http\Middleware\Legacy\Internal\LegacySignature;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

it('recognizes only explicit legacy signatures without invoking callbacks', function (bool $double, string $continuation): void {
    $callback = match ([$double, $continuation]) {
        [false, 'callable'] => static fn (ServerRequestInterface $request, callable $next): never => throw new \LogicException('not invoked'),
        [false, 'closure'] => static fn (ServerRequestInterface $request, Closure $next): never => throw new \LogicException('not invoked'),
        [true, 'callable'] => static fn (ServerRequestInterface $request, ResponseInterface $response, callable $next): never => throw new \LogicException('not invoked'),
        [true, 'closure'] => static fn (ServerRequestInterface $request, ResponseInterface $response, Closure $next): never => throw new \LogicException('not invoked'),
    };

    expect(LegacySignature::recognize($callback, $double))->toBe($callback)
        ->and(LegacySignature::recognize($callback, !$double))->toBeNull();
})->with(['single' => [false], 'double' => [true]])->with(['callable', 'closure']);

it('does not infer a legacy convention from ambiguous or incompatible signatures', function (Closure $callback): void {
    expect(LegacySignature::recognize($callback, false))->toBeNull()
        ->and(LegacySignature::recognize($callback, true))->toBeNull();
})->with([
    'PSR-15 signature' => [static fn (ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface => new Response()],
    'untyped parameters' => [static fn ($request, $next): ResponseInterface => new Response()],
    'concrete request' => [static fn (\Nyholm\Psr7\ServerRequest $request, callable $next): ResponseInterface => new Response()],
    'concrete response' => [static fn (ServerRequestInterface $request, Response $response, callable $next): ResponseInterface => new Response()],
    'ambiguous continuation' => [static fn (ServerRequestInterface $request, callable|RequestHandlerInterface $next): ResponseInterface => new Response()],
    'reference parameter' => [static fn (ServerRequestInterface &$request, callable $next): ResponseInterface => new Response()],
    'variadic continuation' => [static fn (ServerRequestInterface $request, callable ...$next): ResponseInterface => new Response()],
    'extra parameter' => [static fn (ServerRequestInterface $request, callable $next, string $extra = ''): ResponseInterface => new Response()],
]);


#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ExplicitParameter {}

it('does not claim signatures with explicit parameter attributes', function (bool $double): void {
    $callback = $double
        ? static fn (#[ExplicitParameter] ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface => new Response()
        : static fn (ServerRequestInterface $request, #[ExplicitParameter] callable $next): ResponseInterface => new Response();

    expect(LegacySignature::recognize($callback, $double))->toBeNull();
})->with(['single-pass' => [false], 'double-pass' => [true]]);


it('recognizes PSR and Closure type names independently of their spelling case', function (bool $double): void {
    $callback = $double
        ? static fn (\psr\http\message\serverrequestinterface $request, \psr\http\message\responseinterface $response, \cLoSuRe $next): ResponseInterface => new Response()
        : static fn (\psr\http\message\serverrequestinterface $request, \cLoSuRe $next): ResponseInterface => new Response();

    expect(LegacySignature::recognize($callback, $double))->toBe($callback)
        ->and(LegacySignature::recognize($callback, !$double))->toBeNull();
})->with(['single-pass' => [false], 'double-pass' => [true]]);


class ParentMiddleware
{
    public function single(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        return new Response(201);
    }

    public function double(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        return new Response(201);
    }
}

final class ChildMiddleware extends ParentMiddleware {}

it('declines deprecated qualified method pairs without emitting PHP deprecations', function (bool $double): void {
    $target = new ChildMiddleware();
    $method = $double ? 'double' : 'single';
    $diagnostics = [];
    set_error_handler(static function (int $severity, string $message) use (&$diagnostics): bool {
        $diagnostics[] = $message;
        return true;
    });
    try {
        $result = LegacySignature::recognize([$target, 'parent::' . $method], $double);
    } finally {
        restore_error_handler();
    }

    expect($diagnostics)->toBe([])
        ->and($result)->toBeNull();
})->with(['single-pass' => [false], 'double-pass' => [true]]);
