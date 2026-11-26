<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Legacy\Tests\Lifetime;

use Componenta\Http\Middleware\Legacy\DoublePassAdapter;
use Componenta\Http\Middleware\Legacy\SinglePassAdapter;
use DomainException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use WeakReference;

it('does not retain completed requests and continuations after success or failure', function (bool $double, bool $fail): void {
    $single = static fn (ServerRequestInterface $request, callable $next): ResponseInterface => $next($request);
    $middleware = $double
        ? new DoublePassAdapter(
            static fn (ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface => $single($request, $next),
            new Psr17Factory(),
        )
        : new SinglePassAdapter($single);

    $request = new ServerRequest('GET', '/first');
    $terminal = new class($fail) implements RequestHandlerInterface {
        public function __construct(private bool $fail) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ($this->fail) {
                throw new DomainException('original failure');
            }

            return new Response(202, [], $request->getUri()->getPath());
        }
    };
    $requestRef = WeakReference::create($request);
    $terminalRef = WeakReference::create($terminal);
    $caught = false;

    try {
        $response = $middleware->process($request, $terminal);
        expect((string) $response->getBody())->toBe('/first');
        unset($response);
    } catch (DomainException) {
        $caught = true;
    }

    expect($caught)->toBe($fail);
    unset($request, $terminal);
    gc_collect_cycles();

    expect($requestRef->get())->toBeNull()
        ->and($terminalRef->get())->toBeNull();

    $next = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(201, [], $request->getUri()->getPath());
        }
    };
    expect((string) $middleware->process(new ServerRequest('GET', '/after'), $next)->getBody())->toBe('/after');
})->with(['single-pass' => [false], 'double-pass' => [true]])
    ->with(['success' => [false], 'failure' => [true]]);

it('permits repeated continuation calls without substituting the original request', function (bool $double): void {
    $callback = static function (ServerRequestInterface $request, callable $next): ResponseInterface {
        $first = $next($request->withUri($request->getUri()->withPath('/one')));
        $second = $next($request->withUri($request->getUri()->withPath('/two')));
        return new Response(201, [], (string) $first->getBody() . (string) $second->getBody());
    };
    $middleware = $double
        ? new DoublePassAdapter(
            static fn (ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface => $callback($request, $next),
            new Psr17Factory(),
        )
        : new SinglePassAdapter($callback);
    $terminal = new class implements RequestHandlerInterface {
        public array $paths = [];
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->paths[] = $request->getUri()->getPath();
            return new Response(202, [], $request->getUri()->getPath());
        }
    };
    $request = new ServerRequest('GET', '/original');
    for ($i = 0; $i < 2; ++$i) {
        expect((string) $middleware->process($request, $terminal)->getBody())->toBe('/one/two');
    }
    expect($terminal->paths)->toBe(['/one', '/two', '/one', '/two'])
        ->and($request->getUri()->getPath())->toBe('/original');
})->with(['single-pass' => [false], 'double-pass' => [true]]);
