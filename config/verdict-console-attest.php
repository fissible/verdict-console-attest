<?php

declare(strict_types=1);

return [
    'verification' => [
        // Entries use key_id=base64-public-key and add to attest's configured trust.
        'trusted_keys' => [],
        // Null inherits attest.verification.min_anchor_outcome.
        'min_anchor' => null,
    ],
];
