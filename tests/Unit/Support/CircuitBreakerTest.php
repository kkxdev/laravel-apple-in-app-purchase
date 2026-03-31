<?php

namespace Kkxdev\AppleIap\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Kkxdev\AppleIap\Exceptions\CircuitBreakerOpenException;
use Kkxdev\AppleIap\Exceptions\NetworkException;
use Kkxdev\AppleIap\Support\CircuitBreaker;
use Kkxdev\AppleIap\Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    private function makeBreaker(int $failures = 3, int $recovery = 60, int $successes = 2): CircuitBreaker
    {
        $cache = new Repository(new ArrayStore());

        return new CircuitBreaker(
            service:          'test_service',
            cache:            $cache,
            failureThreshold: $failures,
            recoveryTimeout:  $recovery,
            successThreshold: $successes,
        );
    }

    public function test_starts_closed(): void
    {
        $cb = $this->makeBreaker();
        $this->assertSame('closed', $cb->getState());
    }

    public function test_trips_open_after_threshold_failures(): void
    {
        $cb = $this->makeBreaker(failures: 3);

        for ($i = 0; $i < 3; $i++) {
            $cb->recordFailure();
        }

        $this->assertTrue($cb->isOpen());
    }

    public function test_open_circuit_throws_on_allow_request(): void
    {
        $cb = $this->makeBreaker(failures: 1);
        $cb->recordFailure();

        $this->expectException(CircuitBreakerOpenException::class);
        $cb->allowRequest();
    }

    public function test_call_counts_network_exception_as_failure(): void
    {
        $cb = $this->makeBreaker(failures: 1);

        try {
            $cb->call(function () {
                throw new NetworkException('connection refused');
            });
        } catch (NetworkException) {
        }

        $this->assertTrue($cb->isOpen());
    }

    public function test_call_does_not_count_non_network_exception(): void
    {
        $cb = $this->makeBreaker(failures: 1);

        try {
            $cb->call(function () {
                throw new \RuntimeException('client error 422');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('closed', $cb->getState());
        $this->assertSame(0, $cb->getFailureCount());
    }

    public function test_resets_to_closed_after_successful_call(): void
    {
        $cb = $this->makeBreaker(failures: 2);

        $cb->recordFailure();
        $this->assertSame(1, $cb->getFailureCount());

        $cb->recordSuccess();
        $this->assertSame(0, $cb->getFailureCount());
        $this->assertSame('closed', $cb->getState());
    }

    public function test_manual_reset_closes_open_circuit(): void
    {
        $cb = $this->makeBreaker(failures: 1);
        $cb->recordFailure();
        $this->assertTrue($cb->isOpen());

        $cb->reset();
        $this->assertSame('closed', $cb->getState());
    }

    public function test_half_open_transitions_to_closed_after_success_threshold(): void
    {
        $cb = $this->makeBreaker(failures: 1, recovery: 0, successes: 2);

        $cb->recordFailure(); // opens circuit
        $cb->allowRequest();  // recovery_timeout=0 so it immediately goes half-open

        $cb->recordSuccess(); // 1st success in half-open
        $this->assertSame('half_open', $cb->getState());

        $cb->recordSuccess(); // 2nd success → close
        $this->assertSame('closed', $cb->getState());
    }

    public function test_half_open_failure_reopens_circuit(): void
    {
        $cb = $this->makeBreaker(failures: 1, recovery: 0, successes: 2);

        $cb->recordFailure();
        $cb->allowRequest(); // transitions to half-open

        $cb->recordFailure(); // probe fails → reopen

        $this->assertTrue($cb->isOpen());
    }

    public function test_call_returns_value_on_success(): void
    {
        $cb     = $this->makeBreaker();
        $result = $cb->call(fn () => 'hello');

        $this->assertSame('hello', $result);
    }
}
