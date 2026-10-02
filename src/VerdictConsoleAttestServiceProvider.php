<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Illuminate\Support\ServiceProvider;

final class VerdictConsoleAttestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict-console-attest.php', 'verdict-console-attest');

        $this->app->singleton(VerifiesChains::class, AttestChainVerifier::class);
        $this->app->singleton(EvidenceIntegrity::class, AttestEvidenceIntegrity::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/verdict-console-attest.php' => config_path('verdict-console-attest.php'),
        ], 'verdict-console-attest-config');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAndRecordCommand::class]);
        }
    }
}
