---
kind: code
depends_on: []
---

# Proposal: call-log-personal-data-masking

## Summary

Integriq masks secrets in the call log, trace snapshots and outbound message bodies before it stores them. It does not mask personal data. A BSN, a name or an address in a request body reaches the log as plain text. This change adds a declared list of personal data fields and masks them before a log write, in the same pass that masks secrets.

## Why

Matrix row `integriq:obs-redact`, "Hide personal data in the message bodies that get logged." The row was marked built on `http-call-engine` REQ-006, but `lib/Service/Security/SensitiveFieldRegistry.php` matches secret-shaped names only (token, key, secret, password). Following decision 389 the row is set back to `specified` with this change as its plan. The IqLogboek board already draws the masked BSN and name.

This change covers one row: `integriq:obs-redact`.
