<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Legacy\Benchmarks;

use Closure;
use Componenta\Http\Middleware\Legacy\DoublePassAdapter;
use Componenta\Http\Middleware\Legacy\Internal\LegacySignature;
use Componenta\Http\Middleware\Legacy\SinglePassAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(1000)]
#[Bench\Iterations(5)]
final class LegacyMiddlewareBench
{
    private Closure $singleCallback;
    private Closure $doubleCallback;
    private Closure $modernCallback;
    private SinglePassAdapter $single;
    private DoublePassAdapter $double;
    private ServerRequestInterface $request;
    private RequestHandlerInterface $terminal;
    private ResponseFactoryInterface $responseFactory;

    public function setUp(): void
    {
        $this->singleCallback = static fn (ServerRequestInterface $request, callable $next): ResponseInterface => $next($request);
        $this->doubleCallback = static fn (ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface => $next($request);
        $this->modernCallback = static fn (ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface => $next->handle($request);
        $this->responseFactory = new Psr17Factory();
        $this->single = new SinglePassAdapter($this->singleCallback);
        $this->double = new DoublePassAdapter($this->doubleCallback, $this->responseFactory);
        $this->request = new ServerRequest('GET', '/');
        $this->terminal = new class implements RequestHandlerInterface {
            private ResponseInterface $response;

            public function __construct()
            {
                $this->response = new Response(204);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function benchTerminal(): void
    {
        $this->terminal->handle($this->request);
    }

    public function benchConstructSingle(): void
    {
        new SinglePassAdapter($this->singleCallback);
    }

    public function benchConstructDouble(): void
    {
        new DoublePassAdapter($this->doubleCallback, $this->responseFactory);
    }

    public function benchProcessSingle(): void
    {
        $this->single->process($this->request, $this->terminal);
    }

    public function benchProcessDouble(): void
    {
        $this->double->process($this->request, $this->terminal);
    }

    public function benchRecognizeSingle(): void
    {
        LegacySignature::recognize($this->singleCallback, false);
    }

    public function benchRecognizeDouble(): void
    {
        LegacySignature::recognize($this->doubleCallback, true);
    }

    public function benchDeclineModern(): void
    {
        LegacySignature::recognize($this->modernCallback, false);
    }
}
