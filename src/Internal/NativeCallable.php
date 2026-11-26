<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Legacy\Internal;

use Closure;
use InvalidArgumentException;

/** @internal */
final class NativeCallable
{
    /**
     * Capture a native callable, detaching referenced array slots before
     * validation can execute an autoloader.
     *
     * @param callable|array<array-key, mixed>|string $callback
     */
    public static function capture(callable|array|string $callback): Closure
    {
        return self::tryCapture($callback)
            ?? throw new InvalidArgumentException('Invalid or deprecated legacy middleware callable.');
    }

    /**
     * Return null for unsupported native callable forms, without suppressing
     * exceptions thrown by an application autoloader.
     */
    public static function tryCapture(mixed $callback): ?Closure
    {
        if ($callback instanceof Closure) {
            return $callback;
        }

        $owner = null;
        $method = null;

        if (is_array($callback)) {
            if (count($callback) !== 2 || !isset($callback[0], $callback[1])
                || !(is_string($callback[0]) || is_object($callback[0]))
                || !is_string($callback[1])
            ) {
                return null;
            }

            // Copy by value, including referenced receiver and method slots.
            $callback = [$callback[0], $callback[1]];
            [$owner, $method] = $callback;
        } elseif (is_string($callback) && str_contains($callback, '::')) {
            [$owner, $method] = explode('::', $callback, 2);
        }

        // PHP 8.2+ deprecates context-dependent / qualified method pairs.
        // Reject them before is_callable() or Closure::fromCallable() can
        // emit diagnostics. Explicitly bound Closure instances remain valid.
        if (($method !== null && str_contains($method, '::'))
            || (is_string($owner) && in_array(strtolower(ltrim($owner, '\\')), ['self', 'parent', 'static'], true))
        ) {
            return null;
        }

        if (!is_callable($callback)) {
            return null;
        }

        return Closure::fromCallable($callback);
    }
}
