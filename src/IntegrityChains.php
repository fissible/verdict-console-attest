<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Integrity\UnnameableReason;
use Illuminate\Contracts\Config\Repository as Config;

/** Shares the console's configuration-only naming rules between reading and verification. */
final readonly class IntegrityChains
{
    public function __construct(private EvidenceSinkPosture $posture, private Config $config) {}

    /** @return list<string>|UnnameableReason An empty list means integrity is not applicable. */
    public function resolve(): array|UnnameableReason
    {
        $posture = $this->posture->read();
        if ($posture->state !== EvidenceRecordingState::Chained) {
            return [];
        }

        $chain = $posture->recordedBy;
        $hasChain = $posture->configuredChain !== null
            || ($posture->chainConfigured && $chain !== null && $posture->chainResolver === null);
        $hasResolver = $posture->chainResolver !== null;
        if ($hasChain && $hasResolver || ! $hasChain && ! $hasResolver) {
            return UnnameableReason::InvalidTopology;
        }

        if ($hasChain && $chain !== null) {
            return [$chain];
        }

        $configured = $this->config->get('verdict-console.integrity.chains', []);
        $chains = is_array($configured)
            ? array_values(array_filter($configured, fn (mixed $chain): bool => is_string($chain) && $chain !== ''))
            : [];

        return $chains === [] ? UnnameableReason::NoNamedChains : $chains;
    }
}
