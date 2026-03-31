<?php

namespace Kkxdev\AppleIap\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kkxdev\AppleIap\Exceptions\CircuitBreakerOpenException;
use Kkxdev\AppleIap\Exceptions\NetworkException;

/**
 * Cache-backed circuit breaker for Apple API calls.
 *
 * States:
 *   CLOSED   — Normal operation. Failures are counted.
 *   OPEN     — Fast-fail mode. All calls are rejected immediately.
 *   HALF_OPEN — One probe call is allowed through to test recovery.
 *
 * Cache keys (per service):
 *   apple-iap:cb:{service}:state        — 'closed' | 'open' | 'half_open'
 *   apple-iap:cb:{service}:failures     — integer failure count
 *   apple-iap:cb:{service}:last_failure — unix timestamp of last failure
 *   apple-iap:cb:{service}:successes    — integer consecutive success count in half-open
 */
class CircuitBreaker
{
    private const STATE_CLOSED    = 'closed';
    private const STATE_OPEN      = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private const KEY_STATE        = 'state';
    private const KEY_FAILURES     = 'failures';
    private const KEY_LAST_FAILURE = 'last_failure';
    private const KEY_SUCCESSES    = 'successes';

    private const CACHE_TTL = 86400; // 1 day — keys are managed manually, TTL is just a safety net

    public function __construct(
        private string $service,
        private CacheRepository $cache,
        private int $failureThreshold,
        private int $recoveryTimeout,
        private int $successThreshold,
    ) {
    }

    /**
     * Check if a call is allowed to proceed.
     *
     * @throws CircuitBreakerOpenException when the circuit is open
     */
    public function allowRequest(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return;
        }

        if ($state === self::STATE_OPEN) {
            // Check if enough time has passed to attempt recovery
            $lastFailure = (int) $this->cache->get($this->key(self::KEY_LAST_FAILURE), 0);

            if ((time() - $lastFailure) >= $this->recoveryTimeout) {
                $this->transitionTo(self::STATE_HALF_OPEN);
                return; // Allow this probe request through
            }

            throw new CircuitBreakerOpenException($this->service);
        }

        // HALF_OPEN — allow the probe request through
    }

    /**
     * Record a successful call.
     * In HALF_OPEN: increments success count; closes the circuit once threshold met.
     * In CLOSED: resets failure count.
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $successes = (int) $this->cache->get($this->key(self::KEY_SUCCESSES), 0) + 1;
            $this->cache->put($this->key(self::KEY_SUCCESSES), $successes, self::CACHE_TTL);

            if ($successes >= $this->successThreshold) {
                $this->reset();
            }
            return;
        }

        // In closed state, a success resets the failure counter
        if ($state === self::STATE_CLOSED) {
            $this->cache->put($this->key(self::KEY_FAILURES), 0, self::CACHE_TTL);
        }
    }

    /**
     * Record a failed call.
     * Increments failure count; opens the circuit once threshold is reached.
     */
    public function recordFailure(): void
    {
        $state = $this->getState();

        // Any failure in HALF_OPEN immediately re-opens the circuit
        if ($state === self::STATE_HALF_OPEN) {
            $this->trip();
            return;
        }

        if ($state !== self::STATE_CLOSED) {
            return;
        }

        $failures = (int) $this->cache->get($this->key(self::KEY_FAILURES), 0) + 1;
        $this->cache->put($this->key(self::KEY_FAILURES), $failures, self::CACHE_TTL);
        $this->cache->put($this->key(self::KEY_LAST_FAILURE), time(), self::CACHE_TTL);

        if ($failures >= $this->failureThreshold) {
            $this->trip();
        }
    }

    /**
     * Execute a callable with circuit breaker protection.
     *
     * Only NetworkException (transient / 5xx / connectivity failures) counts as a circuit-tripping
     * failure. ApiException with a 4xx status is a caller error and must NOT trip the circuit.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws CircuitBreakerOpenException
     */
    public function call(callable $callback): mixed
    {
        $this->allowRequest();

        try {
            $result = $callback();
            $this->recordSuccess();
            return $result;
        } catch (NetworkException $e) {
            $this->recordFailure();
            throw $e;
        } catch (\Throwable $e) {
            // Non-transient exception (e.g. ApiException 4xx, validation error).
            // Do not affect circuit state — let callers handle it normally.
            throw $e;
        }
    }

    public function getState(): string
    {
        return $this->cache->get($this->key(self::KEY_STATE), self::STATE_CLOSED);
    }

    public function isOpen(): bool
    {
        return $this->getState() === self::STATE_OPEN;
    }

    public function getFailureCount(): int
    {
        return (int) $this->cache->get($this->key(self::KEY_FAILURES), 0);
    }

    /**
     * Manually reset the circuit breaker to closed state (e.g., for admin override).
     */
    public function reset(): void
    {
        $this->transitionTo(self::STATE_CLOSED);
        $this->cache->put($this->key(self::KEY_FAILURES), 0, self::CACHE_TTL);
        $this->cache->put($this->key(self::KEY_SUCCESSES), 0, self::CACHE_TTL);
        $this->cache->forget($this->key(self::KEY_LAST_FAILURE));
    }

    private function trip(): void
    {
        $this->transitionTo(self::STATE_OPEN);
        $this->cache->put($this->key(self::KEY_LAST_FAILURE), time(), self::CACHE_TTL);
        $this->cache->put($this->key(self::KEY_SUCCESSES), 0, self::CACHE_TTL);
    }

    private function transitionTo(string $newState): void
    {
        $this->cache->put($this->key(self::KEY_STATE), $newState, self::CACHE_TTL);
    }

    private function key(string $suffix): string
    {
        return "apple-iap:cb:{$this->service}:{$suffix}";
    }
}
