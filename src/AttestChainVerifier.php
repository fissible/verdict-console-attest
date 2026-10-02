<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Fissible\AttestLaravel\Verification\ChainVerificationResult;
use Fissible\AttestLaravel\Verification\ChainVerifier;
use Fissible\AttestLaravel\Verification\VerificationRequest;

final readonly class AttestChainVerifier implements VerifiesChains
{
    public function __construct(private ChainVerifier $verifier) {}

    public function verify(VerificationRequest $request): ChainVerificationResult
    {
        return $this->verifier->verify($request);
    }
}
