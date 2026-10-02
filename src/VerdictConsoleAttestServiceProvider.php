<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Illuminate\Support\ServiceProvider;

/**
 * The attest bridge's provider.
 *
 * Deliberately empty at the scaffold stage: the package's one job — an attest-backed
 * `EvidenceIntegrity` over attest-laravel's `ChainVerifier` — lands with its tests, and the
 * bindings belong to that change, not this one.
 */
final class VerdictConsoleAttestServiceProvider extends ServiceProvider {}
