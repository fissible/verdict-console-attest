<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Fissible\Attest\Anchor\AnchorOutcome;
use Fissible\AttestLaravel\Verification\VerificationRequest;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

final readonly class VerificationPolicy
{
    public function __construct(private Config $config) {}

    public function request(string $chainId): VerificationRequest
    {
        $keys = $this->config->get('verdict-console-attest.verification.trusted_keys', []);
        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new InvalidArgumentException('Bridge trusted keys must be a list of strings.');
        }
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Bridge trusted keys must be strings.');
            }
        }

        $minimum = $this->config->get('verdict-console-attest.verification.min_anchor');
        if ($minimum !== null && ! is_string($minimum)) {
            throw new InvalidArgumentException('Bridge minimum anchor must be a string or null.');
        }

        return new VerificationRequest(
            chainId: $chainId,
            trustedKeys: $keys,
            minAnchor: $minimum === null ? null : AnchorOutcome::from($minimum),
        );
    }

    public function fingerprint(): string
    {
        $policy = [
            'bridge' => [
                'trusted_keys' => $this->config->get('verdict-console-attest.verification.trusted_keys', []),
                'min_anchor' => $this->config->get('verdict-console-attest.verification.min_anchor'),
            ],
            'attest' => [
                'trusted_keys' => $this->config->get('attest.verification.trusted_keys', []),
                'trusted_key_files' => $this->config->get('attest.verification.trusted_key_files', []),
                'min_anchor_outcome' => $this->config->get('attest.verification.min_anchor_outcome'),
                'require_trusted_key' => $this->config->get('attest.verification.require_trusted_key', true),
                'allow_provider_disagreement' => $this->config->get('attest.verification.allow_provider_disagreement', false),
            ],
            'key_file_contents' => $this->keyFileDigests(),
        ];

        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    /** @return list<array{entry: string, sha256: string|null}> */
    private function keyFileDigests(): array
    {
        $entries = $this->config->get('attest.verification.trusted_key_files', []);
        $entries = is_string($entries) ? [$entries] : $entries;
        if (! is_array($entries)) {
            return [];
        }

        $digests = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            // Match attest's optional key_id= prefix and whitespace handling.
            $path = trim(str_contains($entry, '=') ? explode('=', $entry, 2)[1] : $entry);
            $contents = is_file($path) ? @file_get_contents($path) : false;
            $digests[] = [
                'entry' => $entry,
                // Unreadable/missing is distinct from every readable file, including an empty one.
                'sha256' => $contents === false ? null : hash('sha256', $contents),
            ];
        }

        return $digests;
    }
}
