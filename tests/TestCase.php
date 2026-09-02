<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest\Tests;

use Fissible\AttestLaravel\AttestServiceProvider;
use Fissible\Verdict\VerdictServiceProvider;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Fissible\VerdictConsoleAttest\VerdictConsoleAttestServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the install a bridge host runs: attest-laravel, Verdict, the console core, and this
 * bridge — the full stack the integrity boundary spans.
 *
 * @property Application $app
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AttestServiceProvider::class,
            AiServiceProvider::class,
            VerdictServiceProvider::class,
            VerdictConsoleServiceProvider::class,
            VerdictConsoleAttestServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // A committed key literal is indistinguishable from a leaked production key to secret
        // scanners; the harness mints a fresh one per run.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
