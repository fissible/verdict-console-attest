# Verdict Console Attest

The attest bridge for [`fissible/verdict-console`](https://github.com/fissible/verdict-console):
automated, dated chain-verification claims and persisted gap traces on the console's
evidence-integrity boundary (console ADR 0002).

The console core renders integrity states and records claims; this bridge is what makes the
claims automatic — it verifies a named chain through attest-laravel's stable seam and records the
result with `source: automated`, and it reads Verdict's persisted `chain_gap` marks through the
`ChainGapReader` contract. A host without this bridge keeps honest `Not yet verified.` rendering
forever; nothing in the console core depends on attest.

## Installation

```bash
composer require fissible/verdict-console-attest:^0.1
```

The package registers its provider by discovery. It binds the console's `EvidenceIntegrity`
contract to the attest-backed implementation; the console's own configuration
(`verdict-console.integrity.chains`, Verdict's `verdict.evidence.attest.*`) drives which chains
report.
