<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleAttest;

use Fissible\AttestLaravel\Verification\ChainVerificationResult;
use Fissible\AttestLaravel\Verification\VerificationRequest;

interface VerifiesChains
{
    public function verify(VerificationRequest $request): ChainVerificationResult;
}
