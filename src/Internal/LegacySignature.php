<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Legacy\Internal;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Recognizes only unambiguous, explicitly typed native legacy signatures.
 *
 * @internal
 */
final class LegacySignature
{
    /**
     * Check native string forms without autoloading a potential method owner.
     * Opaque identifiers belong to other resolvers or the service container.
     */
    public static function isNativeStringCandidate(string $definition): bool
    {
        return str_contains($definition, '::') || function_exists($definition);
    }

    public static function recognize(mixed $definition, bool $doublePass): ?Closure
    {
        if ($definition instanceof MiddlewareInterface || $definition instanceof RequestHandlerInterface) {
            return null;
        }

        $callback = NativeCallable::tryCapture($definition);
        if ($callback === null) {
            return null;
        }
        $parameters = (new ReflectionFunction($callback))->getParameters();

        if (count($parameters) !== ($doublePass ? 3 : 2)
            || !self::hasType($parameters[0], ServerRequestInterface::class)
        ) {
            return null;
        }

        foreach ($parameters as $parameter) {
            // Parameter attributes may define an explicit DI binding. Do not
            // infer a positional convention that would override it.
            if ($parameter->isPassedByReference() || $parameter->isVariadic() || $parameter->getAttributes() !== []) {
                return null;
            }
        }

        if ($doublePass && !self::hasType($parameters[1], ResponseInterface::class)) {
            return null;
        }

        $next = $parameters[$doublePass ? 2 : 1];

        return self::hasType($next, 'callable') || self::hasType($next, Closure::class)
            ? $callback
            : null;
    }

    private static function hasType(ReflectionParameter $parameter, string $expected): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && strcasecmp($type->getName(), $expected) === 0;
    }
}
