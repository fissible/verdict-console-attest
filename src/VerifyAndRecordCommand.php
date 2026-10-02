<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Composer\InstalledVersions;
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Fissible\VerdictConsole\Integrity\RecordedVerification;
use Fissible\VerdictConsole\Integrity\UnnameableReason;
use Illuminate\Console\Command;
use Throwable;

final class VerifyAndRecordCommand extends Command
{
    protected $signature = 'verdict-console-attest:verify';

    protected $description = 'Verify configured evidence chains and record dated integrity claims';

    public function handle(
        IntegrityChains $selection,
        VerificationPolicy $policy,
        VerifiesChains $verifier,
        ChainVerificationStore $store,
    ): int {
        $chains = $selection->resolve();
        if ($chains === []) {
            $this->info('No chained sink is configured; nothing to verify.');

            return self::SUCCESS;
        }
        if ($chains instanceof UnnameableReason) {
            $this->error(match ($chains) {
                UnnameableReason::NoNamedChains => 'Chained through a resolver; no chains are named for integrity reporting.',
                UnnameableReason::InvalidTopology => 'Chain configuration is invalid; integrity cannot be reported.',
            });

            return self::FAILURE;
        }

        $allVerified = true;
        foreach ($chains as $chainId) {
            $fingerprint = $policy->fingerprint();
            $ranAt = now()->toDateTimeImmutable();
            $result = null;
            $errorClass = null;
            try {
                $result = $verifier->verify($policy->request($chainId));
                $outcome = $result->outcome === VerificationOutcome::VERIFIED ? 'verified' : 'failed';
            } catch (Throwable $error) {
                $outcome = 'errored';
                $errorClass = $error::class;
            }

            $store->record($chainId, new RecordedVerification(
                outcome: $outcome,
                ranAt: $ranAt,
                ranBy: PHP_SAPI.':'.gethostname(),
                fromSeq: $result->fromSeq ?? 1,
                toSeqRequested: $result?->toSeqRequested,
                verifiedThroughSeq: $result?->verifiedThroughSeq,
                brokenAtSeq: $result?->brokenAtSeq,
                attestOutcome: $result?->outcome->value,
                policyFingerprint: $fingerprint,
                source: 'automated',
                outputDigest: null,
                errorClass: $errorClass,
                // Composer can return null; preserve its answer despite the console's narrower PHPDoc.
                // @phpstan-ignore argument.type
                verifierVersions: $this->verifierVersions(),
            ));
            if ($outcome !== 'verified') {
                $allVerified = false;
            }
        }

        return $allVerified ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, string|null> */
    private function verifierVersions(): array
    {
        $versions = [];
        foreach ([
            'fissible/verdict-console-attest',
            'fissible/attest-laravel',
            'fissible/attest',
            'fissible/verdict',
            'fissible/verdict-console',
        ] as $package) {
            $versions[$package] = InstalledVersions::getPrettyVersion($package);
        }

        return $versions;
    }
}
