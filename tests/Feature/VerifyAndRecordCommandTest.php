<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Verification\ChainStats;
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\Attest\Verification\VerificationResult;
use Fissible\AttestLaravel\Verification\ChainVerificationResult;
use Fissible\AttestLaravel\Verification\VerificationRequest;
use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Fissible\VerdictConsoleAttest\VerifiesChains;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64;

/*
 * ADR 0002 §7's automated verify-and-record: run verification through attest-laravel's stable seam
 * and write the dated claim through the console's own store with source 'automated'. The command is
 * the bridge's whole reason to exist — zero operator ceremony, and the record never launders
 * attest's diagnostics: the outcome word travels verbatim, the message never persists.
 */

const BRIDGE_ATTEST_WRITER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations/create_verdict_console_chain_verifications_table.php.stub')->up();
});

afterEach(function (): void {
    Carbon::setTestNow();

    foreach ($GLOBALS['vcaKeyFiles'] ?? [] as $path) {
        @unlink($path);
    }
    $GLOBALS['vcaKeyFiles'] = [];
});

function bridgeChained(?string $chain = null, ?string $resolver = null): void
{
    config()->set('verdict.evidence.writer', BRIDGE_ATTEST_WRITER);
    config()->set('verdict.evidence.attest.chain', $chain);
    config()->set('verdict.evidence.attest.chain_resolver', $resolver);
}

/** The port double: every request recorded before anything else, results scripted per chain. */
final class ScriptedVerifier implements VerifiesChains
{
    /** @var list<VerificationRequest> */
    public array $requests = [];

    /** @param array<string, ChainVerificationResult|Throwable> $results */
    public function __construct(private readonly array $results = []) {}

    public function verify(VerificationRequest $request): ChainVerificationResult
    {
        $this->requests[] = $request;
        $result = $this->results[$request->chainId] ?? null;

        if ($result instanceof Throwable) {
            throw $result;
        }

        if ($result === null) {
            throw new LogicException('Fixture: no scripted result for '.$request->chainId);
        }

        return $result;
    }
}

/** @param array<string, ChainVerificationResult|Throwable> $results */
function scriptVerifier(array $results): ScriptedVerifier
{
    $verifier = new ScriptedVerifier($results);
    app()->instance(VerifiesChains::class, $verifier);

    return $verifier;
}

function chainResult(
    string $chainId,
    VerificationOutcome $outcome,
    ?int $verifiedThroughSeq,
    ?int $brokenAtSeq = null,
    ?string $message = null,
    int $fromSeq = 1,
    ?int $toSeqRequested = null,
): ChainVerificationResult {
    // A real verifier that walked seq fromSeq..verifiedThroughSeq counted exactly those
    // envelopes — and their signatures count as trusted except under the untrusted outcome.
    $envelopes = $verifiedThroughSeq === null ? 0 : $verifiedThroughSeq - $fromSeq + 1;
    $untrusted = $outcome === VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED;

    return new ChainVerificationResult(
        outcome: $outcome,
        chainId: $chainId,
        fromSeq: $fromSeq,
        toSeqRequested: $toSeqRequested,
        verifiedThroughSeq: $verifiedThroughSeq,
        brokenAtSeq: $brokenAtSeq,
        anchorOutcome: null,
        message: $message,
        verification: new VerificationResult(
            outcome: $outcome,
            chainStats: new ChainStats(
                $chainId,
                $fromSeq,
                $toSeqRequested,
                $envelopes,
                $untrusted ? 0 : $envelopes,
                $untrusted ? $envelopes : 0,
                0,
            ),
            brokenAtSeq: $brokenAtSeq,
            message: $message,
        ),
    );
}

/** A trusted-key entry the real resolver accepts: a generated 32-byte public key. */
function validKeyEntry(string $keyId): string
{
    return $keyId.'='.Base64::encode(KeyPair::generate()->publicKey);
}

/** A real key file at a temp path, holding one valid entry; returns [configEntry, path]. */
function validKeyFile(string $keyId): array
{
    $path = tempnam(sys_get_temp_dir(), 'vca-key-');
    file_put_contents($path, Base64::encode(KeyPair::generate()->publicKey));
    $GLOBALS['vcaKeyFiles'][] = $path;

    return [$keyId.'='.$path, $path];
}

function verificationRow(string $chainId): object
{
    return DB::table('verdict_console_chain_verifications')->where('chain_id', $chainId)->sole();
}

/** The row exactly as persisted, decoded with nothing escaped, for sentinel scans. */
function persistedRowText(string $chainId): string
{
    return json_encode((array) verificationRow($chainId), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('records a verified run as an automated dated claim with execution-derived provenance', function (): void {
    Carbon::setTestNow('2026-10-02T09:30:00+00:00');
    bridgeChained(chain: 'orders-chain');
    scriptVerifier(['orders-chain' => chainResult('orders-chain', VerificationOutcome::VERIFIED, 5)]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    $record = app(ChainVerificationStore::class)->latestFor('orders-chain');

    expect($record?->lastCompleted?->outcome)->toBe('verified')
        ->and($record->lastCompleted->source)->toBe('automated')
        ->and($record->lastCompleted->attestOutcome)->toBe('verified')
        ->and($record->lastCompleted->fromSeq)->toBe(1)
        ->and($record->lastCompleted->toSeqRequested)->toBeNull()
        ->and($record->lastCompleted->verifiedThroughSeq)->toBe(5)
        ->and($record->lastCompleted->brokenAtSeq)->toBeNull()
        ->and($record->lastCompleted->ranAt->format(DATE_ATOM))->toBe('2026-10-02T09:30:00+00:00')
        ->and($record->lastCompleted->ranBy)->toBe(PHP_SAPI.':'.gethostname())
        ->and($record->lastCompleted->outputDigest)->toBeNull()
        ->and($record->lastCompleted->errorClass)->toBeNull()
        ->and($record->lastCompleted->policyFingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($record->lastAttempt?->outcome)->toBe('verified');
});

it('records the actually-installed version of every executed component', function (): void {
    bridgeChained(chain: 'orders-chain');
    scriptVerifier(['orders-chain' => chainResult('orders-chain', VerificationOutcome::VERIFIED, 5)]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    $versions = app(ChainVerificationStore::class)->latestFor('orders-chain')?->lastCompleted?->verifierVersions;

    expect($versions)->toBeArray();
    foreach ([
        'fissible/verdict-console-attest',
        'fissible/attest-laravel',
        'fissible/attest',
        'fissible/verdict',
        'fissible/verdict-console',
    ] as $package) {
        // The claim's provenance is what Composer installed, not a hand-maintained constant. The
        // root package itself has no locked version in a dev checkout; its reference still must
        // be Composer's own answer, so the equality below covers it too.
        expect($versions)->toHaveKey($package)
            ->and($versions[$package])->toBe(InstalledVersions::getPrettyVersion($package));
    }
});

it('records every non-verified outcome as failed, carrying attest\'s word and break verbatim', function (VerificationOutcome $outcome, ?int $brokenAtSeq): void {
    Carbon::setTestNow('2026-10-02T09:30:00+00:00');
    bridgeChained(chain: 'orders-chain');
    scriptVerifier(['orders-chain' => chainResult(
        'orders-chain',
        $outcome,
        verifiedThroughSeq: 1,
        brokenAtSeq: $brokenAtSeq,
        message: 'diagnostic SENTINEL_ATTEST_MESSAGE with secrets',
    )]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $record = app(ChainVerificationStore::class)->latestFor('orders-chain');

    expect($record?->lastCompleted?->outcome)->toBe('failed')
        ->and($record->lastCompleted->attestOutcome)->toBe($outcome->value)
        ->and($record->lastCompleted->source)->toBe('automated')
        ->and($record->lastCompleted->ranAt->format(DATE_ATOM))->toBe('2026-10-02T09:30:00+00:00')
        ->and($record->lastCompleted->ranBy)->toBe(PHP_SAPI.':'.gethostname())
        ->and($record->lastCompleted->verifiedThroughSeq)->toBe(1)
        // The break is the seam's answer carried verbatim — a policy failure has no structural
        // break, and an implementation must not fabricate one from the verified extent.
        ->and($record->lastCompleted->brokenAtSeq)->toBe($brokenAtSeq)
        ->and($record->lastCompleted->policyFingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($record->lastCompleted->verifierVersions['fissible/attest'] ?? null)->toBe(InstalledVersions::getPrettyVersion('fissible/attest'))
        // The row, as persisted, must not carry the seam's message in any column: it commonly
        // names paths and configuration, and no field is allowed to launder it in.
        ->and(persistedRowText('orders-chain'))->not->toContain('SENTINEL_ATTEST_MESSAGE');
})->with([
    // Chain-walk failures break at a sequence; anchor and policy failures complete the walk
    // and report no structural break — the installed verifier leaves brokenAtSeq null there.
    'invalid_chain' => [VerificationOutcome::INVALID_CHAIN, 2],
    'invalid_signature' => [VerificationOutcome::INVALID_SIGNATURE, 2],
    'invalid_anchor' => [VerificationOutcome::INVALID_ANCHOR, null],
    'integrity_verified_untrusted' => [VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, null],
    'provider_disagreement' => [VerificationOutcome::PROVIDER_DISAGREEMENT, null],
    'anchor_below_min' => [VerificationOutcome::ANCHOR_BELOW_MIN, null],
]);

it('records a thrown verification as errored with the exception class only, preserving the standing claim', function (): void {
    Carbon::setTestNow('2026-10-02T09:30:00+00:00');
    bridgeChained(chain: 'orders-chain');
    scriptVerifier(['orders-chain' => chainResult('orders-chain', VerificationOutcome::VERIFIED, 5)]);
    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    Carbon::setTestNow('2026-10-02T10:45:00+00:00');
    config()->set('verdict-console-attest.verification.trusted_keys', [validKeyEntry('app-prod')]);
    scriptVerifier(['orders-chain' => new RuntimeException('could not open SENTINEL_ERROR_PATH chains.sqlite')]);
    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $record = app(ChainVerificationStore::class)->latestFor('orders-chain');

    expect($record?->lastCompleted?->outcome)->toBe('verified', 'An errored attempt never erases the standing claim.')
        ->and($record->lastCompleted->ranAt->format(DATE_ATOM))->toBe('2026-10-02T09:30:00+00:00', 'The standing claim keeps its original instant.')
        ->and($record->lastAttempt?->outcome)->toBe('errored')
        ->and($record->lastAttempt->ranAt->format(DATE_ATOM))->toBe('2026-10-02T10:45:00+00:00', 'The attempt carries fresh provenance.')
        ->and($record->lastAttempt->errorClass)->toBe(RuntimeException::class)
        ->and($record->lastAttempt->attestOutcome)->toBeNull()
        ->and($record->lastAttempt->source)->toBe('automated')
        ->and($record->lastAttempt->ranBy)->toBe(PHP_SAPI.':'.gethostname())
        ->and($record->lastAttempt->verifierVersions['fissible/attest'] ?? null)->toBe(InstalledVersions::getPrettyVersion('fissible/attest'))
        // The policy changed between the runs: the attempt records the policy it ran under,
        // while the standing claim keeps the fingerprint of the run that made it.
        ->and($record->lastAttempt->policyFingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($record->lastAttempt->policyFingerprint)->not->toBe($record->lastCompleted->policyFingerprint)
        ->and(persistedRowText('orders-chain'))->not->toContain('SENTINEL_ERROR_PATH');
});

it('records an engine Error the same way: a throw is a throw', function (): void {
    bridgeChained(chain: 'orders-chain');
    scriptVerifier(['orders-chain' => new TypeError('argument SENTINEL_TYPE_ERROR is wrong')]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $record = app(ChainVerificationStore::class)->latestFor('orders-chain');

    expect($record?->lastAttempt?->outcome)->toBe('errored')
        ->and($record->lastAttempt->errorClass)->toBe(TypeError::class)
        ->and($record->lastCompleted)->toBeNull()
        ->and(persistedRowText('orders-chain'))->not->toContain('SENTINEL_TYPE_ERROR');
});

it('verifies every named chain for a resolver topology, one request per chain, in the named order', function (): void {
    bridgeChained(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-b', 'tenant-a']);
    $verifier = scriptVerifier([
        'tenant-b' => chainResult('tenant-b', VerificationOutcome::VERIFIED, 2),
        'tenant-a' => chainResult('tenant-a', VerificationOutcome::VERIFIED, 9),
    ]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    expect(array_map(fn (VerificationRequest $r) => $r->chainId, $verifier->requests))->toBe(['tenant-b', 'tenant-a'])
        ->and(app(ChainVerificationStore::class)->latestFor('tenant-a')?->lastCompleted?->verifiedThroughSeq)->toBe(9)
        ->and(app(ChainVerificationStore::class)->latestFor('tenant-b')?->lastCompleted?->verifiedThroughSeq)->toBe(2);
});

it('verifies the fixed chain alone, ignoring a named list beside it', function (): void {
    bridgeChained(chain: 'orders-chain');
    config()->set('verdict-console.integrity.chains', ['tenant-a', 'tenant-b']);
    $verifier = scriptVerifier(['orders-chain' => chainResult('orders-chain', VerificationOutcome::VERIFIED, 5)]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    expect(array_map(fn (VerificationRequest $r) => $r->chainId, $verifier->requests))->toBe(['orders-chain'])
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(1);
});

it('keeps verifying remaining chains after one throws, records both, and exits non-zero', function (): void {
    bridgeChained(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-b', 'tenant-a']);
    $verifier = scriptVerifier([
        'tenant-b' => new RuntimeException('verification backend down'),
        'tenant-a' => chainResult('tenant-a', VerificationOutcome::VERIFIED, 3),
    ]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    expect(array_map(fn (VerificationRequest $r) => $r->chainId, $verifier->requests))->toBe(['tenant-b', 'tenant-a'], 'A throw on one chain must not abort its neighbors.')
        ->and(app(ChainVerificationStore::class)->latestFor('tenant-b')?->lastAttempt?->outcome)->toBe('errored')
        ->and(app(ChainVerificationStore::class)->latestFor('tenant-a')?->lastCompleted?->outcome)->toBe('verified');
});

it('keeps verifying remaining chains after one fails, and still exits non-zero', function (): void {
    bridgeChained(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-b', 'tenant-a']);
    scriptVerifier([
        'tenant-b' => chainResult('tenant-b', VerificationOutcome::INVALID_CHAIN, null, brokenAtSeq: 1),
        'tenant-a' => chainResult('tenant-a', VerificationOutcome::VERIFIED, 3),
    ]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $failed = app(ChainVerificationStore::class)->latestFor('tenant-b')?->lastCompleted;

    expect($failed?->outcome)->toBe('failed')
        // A first-envelope failure verified nothing and broke at 1 — both carried verbatim, so a
        // null extent cannot be coerced to 0 nor the break fabricated elsewhere.
        ->and($failed->verifiedThroughSeq)->toBeNull()
        ->and($failed->brokenAtSeq)->toBe(1)
        ->and(app(ChainVerificationStore::class)->latestFor('tenant-a')?->lastCompleted?->outcome)->toBe('verified');
});

it('builds every request from the configured verification policy, whole-range by default', function (): void {
    bridgeChained(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', ['tenant-b', 'tenant-a']);
    $entry = validKeyEntry('app-prod');
    config()->set('verdict-console-attest.verification.trusted_keys', [$entry]);
    config()->set('verdict-console-attest.verification.min_anchor', 'local_only');
    $verifier = scriptVerifier([
        'tenant-b' => chainResult('tenant-b', VerificationOutcome::VERIFIED, 2),
        'tenant-a' => chainResult('tenant-a', VerificationOutcome::VERIFIED, 9),
    ]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    expect(array_map(fn (VerificationRequest $r) => $r->chainId, $verifier->requests))->toBe(['tenant-b', 'tenant-a']);

    foreach ($verifier->requests as $request) {
        expect($request->trustedKeys)->toBe([$entry])
            ->and($request->minAnchor?->value)->toBe('local_only')
            ->and($request->fromSeq)->toBe(1, 'The automated claim covers the whole chain.')
            ->and($request->toSeq)->toBeNull();
    }
});

it('fingerprints the policy so differing inputs cannot masquerade as the same claim', function (Closure|array $changed): void {
    $run = function () {
        bridgeChained(chain: 'orders-chain');
        scriptVerifier(['orders-chain' => chainResult('orders-chain', VerificationOutcome::VERIFIED, 5)]);
        $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

        return app(ChainVerificationStore::class)->latestFor('orders-chain')?->lastCompleted?->policyFingerprint;
    };

    config()->set('verdict-console-attest.verification.trusted_keys', [validKeyEntry('app-prod')]);
    config()->set('verdict-console-attest.verification.min_anchor', null);
    $baseline = $run();
    $repeat = $run();

    foreach (($changed instanceof Closure ? $changed() : $changed) as $key => $value) {
        config()->set($key, $value);
    }
    $differing = $run();

    expect($repeat)->toBe($baseline, 'The same policy is the same claim identity.')
        ->and($differing)->not->toBe($baseline);
})->with([
    'different bridge trusted keys' => [fn (): array => ['verdict-console-attest.verification.trusted_keys' => [validKeyEntry('app-prod')]]],
    'different bridge anchor minimum' => [['verdict-console-attest.verification.min_anchor' => 'local_only']],
    // The seam inherits these even when the request omits them (VerifyChain reads the configured
    // minimum and disagreement allowance, and TrustedKeyResolver merges configured trust), so the
    // same request under different inherited configuration is a different policy — the fingerprint
    // must say so.
    'different inherited trusted keys' => [fn (): array => ['attest.verification.trusted_keys' => [validKeyEntry('legacy')]]],
    'different inherited trusted key files' => [fn (): array => ['attest.verification.trusted_key_files' => [validKeyFile('legacy')[0]]]],
    'different inherited anchor minimum' => [['attest.verification.min_anchor_outcome' => 'local_only']],
    'different inherited trust requirement' => [['attest.verification.require_trusted_key' => false]],
    'different inherited disagreement allowance' => [['attest.verification.allow_provider_disagreement' => true]],
]);

it('re-fingerprints a key file whose contents rotate under an unchanged configuration', function (): void {
    $run = function () {
        bridgeChained(chain: 'orders-chain');
        scriptVerifier(['orders-chain' => chainResult('orders-chain', VerificationOutcome::VERIFIED, 5)]);
        $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

        return app(ChainVerificationStore::class)->latestFor('orders-chain')?->lastCompleted?->policyFingerprint;
    };

    [$entry, $path] = validKeyFile('legacy');
    config()->set('attest.verification.trusted_key_files', [$entry]);
    $before = $run();

    // The resolver trusts the file's CONTENTS: rotating the key at the same path is a different
    // policy, and a fingerprint built from configuration strings alone would miss it.
    file_put_contents($path, Base64::encode(KeyPair::generate()->publicKey));
    $after = $run();

    expect($after)->not->toBe($before);
});

it('does nothing on a host with no chained sink, successfully', function (): void {
    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', null);
    $verifier = scriptVerifier([]);

    $this->artisan('verdict-console-attest:verify')
        ->expectsOutputToContain('No chained sink is configured; nothing to verify.')
        ->assertExitCode(0);

    expect($verifier->requests)->toBe([])
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
});

it('treats an explicit non-attest writer as not chained even when the recorder names attest', function (): void {
    config()->set('verdict.evidence.writer', 'App\\Evidence\\DatabaseRecorder');
    config()->set('verdict.evidence.recorder', BRIDGE_ATTEST_WRITER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');
    $verifier = scriptVerifier([]);

    $this->artisan('verdict-console-attest:verify')
        ->expectsOutputToContain('No chained sink is configured; nothing to verify.')
        ->assertExitCode(0);

    expect($verifier->requests)->toBe([])
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
});

it('refuses an unnamed resolver topology with the boundary\'s own copy, writing nothing', function (): void {
    bridgeChained(resolver: 'App\\Tenancy\\ChainResolver');
    config()->set('verdict-console.integrity.chains', []);
    $verifier = scriptVerifier([]);

    $this->artisan('verdict-console-attest:verify')
        ->expectsOutputToContain('Chained through a resolver; no chains are named for integrity reporting.')
        ->assertExitCode(1);

    expect($verifier->requests)->toBe([])
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
});

it('refuses an invalid topology without verifying anything', function (): void {
    bridgeChained(chain: 'orders-chain', resolver: 'App\\Tenancy\\ChainResolver');
    $verifier = scriptVerifier([]);

    $this->artisan('verdict-console-attest:verify')
        ->expectsOutputToContain('Chain configuration is invalid; integrity cannot be reported.')
        ->assertExitCode(1);

    expect($verifier->requests)->toBe([])
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
});
