<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Fissible\Verdict\Contracts\ChainGapReader;
use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Fissible\VerdictConsole\Integrity\ChainIntegrityState;
use Fissible\VerdictConsole\Integrity\ChainIntegrityView;
use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Fissible\VerdictConsole\Integrity\GapTrace;
use Fissible\VerdictConsole\Integrity\UnnameableReason;
use Throwable;

final readonly class AttestEvidenceIntegrity implements EvidenceIntegrity
{
    public function __construct(
        private IntegrityChains $chains,
        private ChainVerificationStore $store,
        private ChainGapReader $gaps,
    ) {}

    /** @return list<ChainIntegrityView> */
    public function chains(): array
    {
        $chains = $this->chains->resolve();
        if ($chains instanceof UnnameableReason) {
            return [new ChainIntegrityView('', ChainIntegrityState::Unnameable, $chains, null, null, null)];
        }

        return array_map($this->view(...), $chains);
    }

    private function view(string $chainId): ChainIntegrityView
    {
        $latest = $this->store->latestFor($chainId);
        $completed = $latest?->lastCompleted;
        $state = match ($completed?->outcome) {
            'verified' => ChainIntegrityState::Verified,
            'failed' => ChainIntegrityState::Failed,
            default => ChainIntegrityState::Unverified,
        };

        try {
            $summary = $this->gaps->gapsForChain($chainId);
            $gaps = new GapTrace($summary->persistedCount, $summary->latestMarkAt);
        } catch (Throwable) {
            $gaps = null;
        }

        return new ChainIntegrityView($chainId, $state, null, $completed, $latest?->lastAttempt, $gaps);
    }
}
