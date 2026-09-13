<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * assertStringNotContainsString on an HTML response dumps the entire page
     * into the failure report, which makes scenario runs unreadable. These
     * assert on a boolean instead, so a failure shows only the message.
     */
    protected function assertStringNotContainsStringQuietly(string $needle, string $haystack, string $message): void
    {
        $this->assertFalse(str_contains($haystack, $needle), $message);
    }

    protected function assertStringContainsStringQuietly(string $needle, string $haystack, string $message): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $message);
    }
}
