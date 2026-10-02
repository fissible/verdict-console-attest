<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ChainGapReader;
use Fissible\Verdict\Evidence\ChainGapSummary;
use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Fissible\VerdictConsole\Integrity\ChainIntegrityState;
use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Fissible\VerdictConsole\Integrity\GapTrace;
use Fissible\VerdictConsole\Integrity\RecordedVerification;
use Fissible\VerdictConsole\Integrity\UnnameableReason;

/*
 * The attest-backed read boundary (ADR 0002 §7). Everything the console's NullEvidenceIntegrity
 * answers from configuration and the verification store, this provider answers identically — plus
 * the one thing only the bridge may do: fill `gaps` from Verdict's ChainGapReader, because the
 * bridge owns attest-adjacent storage knowledge and the core never reads gap marks.
 */

const ATTEST_WRITER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations/create_verdict_console_chain_verifications_table.php.stub')->up();
});

/** Point the effective sink at attest with a fixed chain, a resolver, or both. */
function chainedSink(?string $chain = null, ?string $resolver = null): void
{
    config()->set('verdict.evidence.writer', ATTEST_WRITER);
    config()->set('verdict.evidence.attest.chain', $chain);
    config()->set('verdict.evidence.attest.chain_resolver', $resolver);
}

/**
 * A reader whose per-chain summaries the tests script; unknown chains have no marks. Every
 * attempted read is recorded before the failure check, so a failed access still shows in history.
 */
final class ScriptedGapReader implements ChainGapReader
{
    /**
     * @param array<string, ChainGapSummary> $summaries
     * @param list<string> $brokenChains
     */
    public function __construct(
        private readonly array $summaries = [],
        private readonly array $brokenChains = [],
        private readonly bool $broken = false,
    ) {}

    /** @var list<string> */
    public array $reads = [];

    public function gapsForChain(string $chainId): ChainGapSummary
    {
        $this->reads[] = $chainId;

        if ($this->broken || in_array($chainId, $this->brokenChains, true)) {
            throw new RuntimeException('gap storage unavailable: SENTINEL_GAP_DIAGNOSTIC');
        }

        return $this->summaries[$chainId] ?? ChainGapSummary::none();
    }
}

/**
 * @param array<string, ChainGapSummary> $summaries
 * @param list<string> $brokenChains
 */
function gapReader(array $summaries = [], bool $broken = false, array $brokenChains = []): ScriptedGapReader
{
    $reader = new ScriptedGapReader($summaries, $brokenChains, $broken);
    app()->instance(ChainGapReader::class, $reader);

    return $reader;
}

function integrityRecord(string $outcome): RecordedVerification
{
    return new RecordedVerification(
        outcome: $outcome,
        ranAt: new DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        ranBy: 'test:host',
        fromSeq: 1,
        toSeqRequested: null,
        verifiedThroughSeq: $outcome === 'verified' ? 7 : null,
        brokenAtSeq: $outcome === 'failed' ? 3 : null,
        attestOutcome: $outcome === 'errored' ? null : ($outcome === 'verified' ? 'verified' : 'invalid_signature'),
        policyFingerprint: str_repeat('ab', 32),
        source: 'automated',
        outputDigest: null,
        errorClass: $outcome === 'errored' ? RuntimeException::class : null,
        verifierVersions: ['fissible/attest' => '1.4.1'],
    );
}

it('answers through the container with bridge behavior: the resolved boundary carries gap traces', function (): void {
    chainedSink(chain: 'orders-chain');
    gapReader(['orders-chain' => new ChainGapSummary(3, null)]);

    $views = app(EvidenceIntegrity::class)->chains();

    // Behavior, not class identity: the console's null default always reports gaps as null, so a
    // populated trace through the resolved contract proves the bridge's provider won the binding.
    expect($views)->toHaveCount(1)
        ->and($views[0]->gaps)->toBeInstanceOf(GapTrace::class)
        ->and($views[0]->gaps->persistedMarks)->toBe(3);
});

it('reports nothing when no evidence writer resolves at all', function (): void {
    gapReader();
    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', null);

    expect(app(EvidenceIntegrity::class)->chains())->toBe([]);
});

it('reports nothing for a non-attest writer even when stale chain settings sit beside it', function (): void {
    $reader = gapReader();
    config()->set('verdict.evidence.writer', 'App\\Evidence\\DatabaseRecorder');
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    expect(app(EvidenceIntegrity::class)->chains())->toBe([])
        ->and($reader->reads)->toBe([], 'Inert chain settings beside a non-attest writer must not read gap storage.');
});

it('honors writer precedence: an explicit non-attest writer beats an attest recorder fallback', function (): void {
    $reader = gapReader();
    config()->set('verdict.evidence.writer', 'App\\Evidence\\DatabaseRecorder');
    config()->set('verdict.evidence.recorder', ATTEST_WRITER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    expect(app(EvidenceIntegrity::class)->chains())->toBe([])
        ->and($reader->reads)->toBe([], 'The recorder fallback only applies when no writer is set.');
});

it('derives the chained posture through recorder fallback when no writer is set, like the console does', function (): void {
    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', ATTEST_WRITER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');
    config()->set('verdict.evidence.attest.chain_resolver', null);
    gapReader();

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->chainId)->toBe('orders-chain');
});

it('reports a fixed chain Unverified with a zero-floor gap trace before any verification', function (): void {
    chainedSink(chain: 'orders-chain');
    gapReader(['orders-chain' => ChainGapSummary::none()]);

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->chainId)->toBe('orders-chain')
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unverified)
        ->and($views[0]->lastCompleted)->toBeNull()
        // Zero marks is a floor the bridge can honestly report; it is not "no gap information".
        ->and($views[0]->gaps)->toBeInstanceOf(GapTrace::class)
        ->and($views[0]->gaps->persistedMarks)->toBe(0)
        ->and($views[0]->gaps->latestMarkAt)->toBeNull();
});

it('derives the state from the standing completed claim and carries the gap trace beside it', function (): void {
    chainedSink(chain: 'orders-chain');
    $markedAt = new DateTimeImmutable('2026-09-30T08:00:00+00:00');
    gapReader(['orders-chain' => new ChainGapSummary(2, $markedAt)]);
    app(ChainVerificationStore::class)->record('orders-chain', integrityRecord('verified'));

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views[0]->state)->toBe(ChainIntegrityState::Verified)
        ->and($views[0]->lastCompleted?->verifiedThroughSeq)->toBe(7)
        ->and($views[0]->gaps?->persistedMarks)->toBe(2)
        ->and($views[0]->gaps?->latestMarkAt?->format(DATE_ATOM))->toBe($markedAt->format(DATE_ATOM));
});

it('derives each state from lastCompleted alone, never from a newer attempt', function (array $records, ChainIntegrityState $state): void {
    chainedSink(chain: 'orders-chain');
    gapReader();
    $store = app(ChainVerificationStore::class);
    foreach ($records as $outcome) {
        $store->record('orders-chain', integrityRecord($outcome));
    }

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views[0]->state)->toBe($state);
})->with([
    'verified then errored stays Verified' => [['verified', 'errored'], ChainIntegrityState::Verified],
    'failed then errored stays Failed' => [['failed', 'errored'], ChainIntegrityState::Failed],
    'errored alone is Unverified' => [['errored'], ChainIntegrityState::Unverified],
]);

it('renders the errored attempt beside the standing claim', function (): void {
    chainedSink(chain: 'orders-chain');
    gapReader();
    $store = app(ChainVerificationStore::class);
    $store->record('orders-chain', integrityRecord('verified'));
    $store->record('orders-chain', integrityRecord('errored'));

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views[0]->lastCompleted?->outcome)->toBe('verified')
        ->and($views[0]->lastAttempt?->outcome)->toBe('errored')
        ->and($views[0]->lastAttempt?->errorClass)->toBe(RuntimeException::class);
});

it('reports resolver-named chains in the named order, each with its own gap trace', function (): void {
    chainedSink(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-b', 'tenant-a']);
    $reader = gapReader([
        'tenant-b' => new ChainGapSummary(1, new DateTimeImmutable('2026-09-29T00:00:00+00:00')),
        'tenant-a' => ChainGapSummary::none(),
    ]);
    app(ChainVerificationStore::class)->record('tenant-a', integrityRecord('failed'));

    $views = app(EvidenceIntegrity::class)->chains();

    expect(array_map(fn ($v) => $v->chainId, $views))->toBe(['tenant-b', 'tenant-a'])
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unverified)
        ->and($views[0]->gaps?->persistedMarks)->toBe(1)
        ->and($views[1]->state)->toBe(ChainIntegrityState::Failed)
        ->and($views[1]->lastCompleted?->attestOutcome)->toBe('invalid_signature')
        // The second chain's zero-floor trace is asserted, not just that its read happened.
        ->and($views[1]->gaps?->persistedMarks)->toBe(0)
        ->and($views[1]->gaps?->latestMarkAt)->toBeNull()
        ->and($reader->reads)->toBe(['tenant-b', 'tenant-a']);
});

it('filters a malformed named list the way the console does: non-strings and empties drop out', function (): void {
    chainedSink(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-a', 7, '', null, 'tenant-b']);
    gapReader();

    $views = app(EvidenceIntegrity::class)->chains();

    expect(array_map(fn ($v) => $v->chainId, $views))->toBe(['tenant-a', 'tenant-b']);
});

it('refuses to derive chains for an unnamed resolver, with no gap trace', function (): void {
    chainedSink(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', []);
    $reader = gapReader();

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unnameable)
        ->and($views[0]->unnameableReason)->toBe(UnnameableReason::NoNamedChains)
        ->and($views[0]->gaps)->toBeNull()
        ->and($reader->reads)->toBe([], 'An unnameable topology must not read gap storage.');
});

it('fails closed to InvalidTopology without reading gap storage', function (?string $chain, ?string $resolver): void {
    chainedSink(chain: $chain, resolver: $resolver);
    $reader = gapReader();

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unnameable)
        ->and($views[0]->unnameableReason)->toBe(UnnameableReason::InvalidTopology)
        ->and($views[0]->gaps)->toBeNull()
        ->and($reader->reads)->toBe([]);
})->with([
    'a fixed chain and a resolver both set' => ['orders-chain', 'App\\Tenancy\\ChainResolver'],
    'an attest sink with neither set' => [null, null],
]);

it('degrades only the broken chain\'s gaps to null; its neighbors keep their traces', function (): void {
    chainedSink(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-a', 'tenant-b']);
    $reader = gapReader(
        summaries: ['tenant-b' => new ChainGapSummary(4, null)],
        brokenChains: ['tenant-a'],
    );
    app(ChainVerificationStore::class)->record('tenant-a', integrityRecord('verified'));

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views[0]->chainId)->toBe('tenant-a')
        ->and($views[0]->state)->toBe(ChainIntegrityState::Verified, 'A gap-read failure degrades to "no gap information", never to a failed view.')
        ->and($views[0]->gaps)->toBeNull()
        ->and($views[1]->gaps?->persistedMarks)->toBe(4)
        ->and($reader->reads)->toBe(['tenant-a', 'tenant-b'], 'The failed read was attempted, then its neighbor still read.');
});

it('ignores the named-chains list beside a fixed chain: the fixed chain alone reports', function (): void {
    chainedSink(chain: 'orders-chain');
    config()->set('verdict-console.integrity.chains', ['tenant-a', 'tenant-b']);
    gapReader();

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->chainId)->toBe('orders-chain');
});
