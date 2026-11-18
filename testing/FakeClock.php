<?php

/*
 * A clock the tests move by hand, so the tests can check the logs are timestamped with it.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLogger\Testing;


use Psr\Clock\ClockInterface;


final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $m_now;


    public function __construct()
    {
        $this->m_now = new \DateTimeImmutable('2026-01-01 12:00:00.000000', new \DateTimeZone('UTC'));
    }


    public function now() : \DateTimeImmutable
    {
        return $this->m_now;
    }


    /**
     * Move the time forward, or back with a negative number.
     * @param int $seconds
     */
    public function advance(int $seconds) : void
    {
        $this->m_now = $this->m_now->modify("{$seconds} seconds");
    }
}
