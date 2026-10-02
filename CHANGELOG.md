# Changelog

All notable changes to Verdict Console Attest will be documented in this file.

## [Unreleased]

- **The attest-backed evidence-integrity boundary (ADR 0002 §7, verdict-console#120).** The bridge
  binds `EvidenceIntegrity` to an attest-backed provider: the console's naming, topology, and
  standing-claim rules unchanged, plus the one thing only the bridge may do — a per-chain `GapTrace`
  read from Verdict's `ChainGapReader`, degrading to "no gap information" (never a failed view) when
  gap storage cannot be read. Unnameable and not-applicable topologies never touch gap storage.

- **Automated verify-and-record: `verdict-console-attest:verify`.** One run per named chain through
  attest-laravel's stable `ChainVerifier` seam (behind the package's `VerifiesChains` port), each
  recorded through the console's verification store as a dated claim with source `automated`:
  attest's outcome word and break position carried verbatim (`verified` only for `VERIFIED`; every
  other outcome records `failed`; a throw records `errored` with the exception class only — the
  seam's message is never persisted), execution-derived `ranAt`/`ranBy`, the installed version of
  every executed component, and an effective-policy fingerprint covering the bridge's own
  `verification.trusted_keys`/`min_anchor` block, the inherited `attest.verification.*` dimensions,
  and the *contents* of trusted-key files — so a key rotation at an unchanged path is a different
  policy. A failure or throw on one chain never stops its neighbors; the command exits zero only
  when every chain verified. Not-applicable hosts are a successful no-op; unnameable topologies
  refuse with the boundary's own copy.
