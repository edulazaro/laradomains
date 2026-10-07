<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Facades\Domains;
use EduLazaro\Laradomains\LaradomainsServiceProvider;
use EduLazaro\Laradomains\Support\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaradomainsServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Domains' => Domains::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('laradomains.public_suffix_list', '/nonexistent/psl.dat');
    }

    protected function tearDown(): void
    {
        Http::flushHooks();
        parent::tearDown();
    }
}
