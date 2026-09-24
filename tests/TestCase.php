<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                // Keep Redis-backed rate-limit state isolated between cases;
                // each test still exercises the shared store within its own flow.
                $this->app['cache']->flush();
            }
        } finally {
            parent::tearDown();
        }
    }
}
