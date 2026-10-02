<?php

declare(strict_types=1);

use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Fissible\VerdictConsole\Integrity\ChainIntegrityState;
use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ParagonIE\ConstantTime\Base64;

/*
 * The whole stack, nothing scripted: a real signed attest chain on the test database, verified
 * through attest-laravel's real ChainVerifier behind the bridge's port, recorded through the
 * console's real store. This is the pair of claims the bridge exists to automate — a verified
 * chain reads verified, and a tampered one reads failed in attest's own words.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const E2E_ATTEST_WRITER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations/create_verdict_console_chain_verifications_table.php.stub')->up();

    $this->keyPair = KeyPair::generate();
    $this->signer = new SodiumSigner($this->keyPair, 'app-prod');

    config()->set('verdict.evidence.writer', E2E_ATTEST_WRITER);
    config()->set('verdict.evidence.attest.chain', 'console-e2e');
    config()->set('verdict-console-attest.verification.trusted_keys', [
        'app-prod='.Base64::encode($this->keyPair->publicKey),
    ]);
});

function buildRealChain(object $test, int $count): void
{
    $chain = EvidenceChain::open(app(ChainStore::class), 'console-e2e', $test->signer);

    for ($i = 1; $i <= $count; $i++) {
        $chain->record('console.event', ['n' => $i]);
    }
}

it('verifies a real trusted chain end to end, and the public boundary reads the claim back', function (): void {
    buildRealChain($this, 3);

    // One real gap mark, in the shape Verdict's own DatabaseChainGapReader reads: the bridge's
    // other integration seam runs against the real reader here, not a scripted one.
    Schema::create('verdict_evidence', function ($table): void {
        $table->id();
        $table->string('record_type');
        $table->text('reason')->nullable();
        $table->timestamp('recorded_at')->nullable();
    });
    DB::table('verdict_evidence')->insert([
        'record_type' => 'chain_gap',
        'reason' => json_encode(['chain' => 'console-e2e', 'class' => 'RuntimeException']),
        'recorded_at' => '2026-10-01 06:00:00',
    ]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);

    $record = app(ChainVerificationStore::class)->latestFor('console-e2e');

    expect($record?->lastCompleted?->outcome)->toBe('verified')
        ->and($record->lastCompleted->attestOutcome)->toBe('verified')
        ->and($record->lastCompleted->source)->toBe('automated')
        ->and($record->lastCompleted->fromSeq)->toBe(1)
        ->and($record->lastCompleted->verifiedThroughSeq)->toBe(3)
        ->and($record->lastCompleted->brokenAtSeq)->toBeNull();

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->chainId)->toBe('console-e2e')
        ->and($views[0]->state)->toBe(ChainIntegrityState::Verified)
        ->and($views[0]->lastCompleted?->verifiedThroughSeq)->toBe(3)
        ->and($views[0]->gaps?->persistedMarks)->toBe(1)
        ->and($views[0]->gaps?->latestMarkAt?->format('Y-m-d H:i:s'))->toBe('2026-10-01 06:00:00');
});

it('enforces trust through the real adapter: a chain signed by an untrusted key fails as untrusted', function (): void {
    buildRealChain($this, 2);

    // The configured trust names a different key than the one that signed the chain; the walk
    // completes (structure is intact) and the policy refuses it in attest's own word.
    config()->set('verdict-console-attest.verification.trusted_keys', [
        'other-key='.Base64::encode(KeyPair::generate()->publicKey),
    ]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $record = app(ChainVerificationStore::class)->latestFor('console-e2e');

    expect($record?->lastCompleted?->outcome)->toBe('failed')
        ->and($record->lastCompleted->attestOutcome)->toBe('integrity_verified_untrusted')
        ->and($record->lastCompleted->verifiedThroughSeq)->toBe(2, 'The structural walk covered the whole chain.')
        ->and($record->lastCompleted->brokenAtSeq)->toBeNull();
});

it('enforces the anchor policy through the real adapter: an unanchored chain under a minimum fails', function (): void {
    buildRealChain($this, 2);
    config()->set('verdict-console-attest.verification.min_anchor', 'local_only');

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $record = app(ChainVerificationStore::class)->latestFor('console-e2e');

    // An adapter that dropped minAnchor would verify this chain; the recorded failure in attest's
    // own word proves the policy crossed the port into the real seam.
    expect($record?->lastCompleted?->outcome)->toBe('failed')
        ->and($record->lastCompleted->attestOutcome)->toBe('anchor_below_min')
        ->and($record->lastCompleted->verifiedThroughSeq)->toBe(2)
        ->and($record->lastCompleted->brokenAtSeq)->toBeNull();
});

it('replaces a standing verified claim when tampering is detected on a rerun', function (): void {
    buildRealChain($this, 3);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(0);
    expect(app(ChainVerificationStore::class)->latestFor('console-e2e')?->lastCompleted?->outcome)->toBe('verified');

    $raw = DB::table('attest_envelopes')
        ->where('chain_id', 'console-e2e')->where('sequence', 2)->value('raw_envelope');
    $decoded = json_decode((string) $raw, associative: true, flags: JSON_THROW_ON_ERROR);
    $decoded['sig'] = 'base64:'.Base64::encode(str_repeat("\x00", 64));
    DB::table('attest_envelopes')
        ->where('chain_id', 'console-e2e')->where('sequence', 2)
        ->update(['raw_envelope' => json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

    $this->artisan('verdict-console-attest:verify')->assertExitCode(1);

    $record = app(ChainVerificationStore::class)->latestFor('console-e2e');

    // The failed run is a completed verification: it REPLACES the standing verified claim rather
    // than leaving a stale Verified beside detected tampering — and the boundary says so.
    expect($record?->lastCompleted?->outcome)->toBe('failed')
        ->and($record->lastCompleted->attestOutcome)->toBe('invalid_signature')
        ->and($record->lastCompleted->verifiedThroughSeq)->toBe(1)
        ->and($record->lastCompleted->brokenAtSeq)->toBe(2)
        ->and(app(EvidenceIntegrity::class)->chains()[0]->state)->toBe(ChainIntegrityState::Failed);
});
