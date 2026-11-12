---
kind: code
depends_on: []
---

# Proposal: sources-ws-security-encryption

## Summary

Integriq signs SOAP messages with WS-Security on the Digikoppeling WUS path. It cannot encrypt them, and it cannot decrypt an encrypted answer. This change adds WS-Security encryption of the SOAP body to a SOAP source, with the partner's certificate, and decryption of encrypted answers with the organisation's key.

## Why

Matrix row `integriq:src-ws-security`, "Sign and encrypt SOAP messages with WS-Security." The row was marked built on `digikoppeling-adapter` REQ-DK-002, which signs only; `grep -iE 'xmlenc|EncryptedData'` over `lib/` finds no encryption. Following decision 389 the row is set back to `specified` with this change as its plan.

This change covers one row: `integriq:src-ws-security`.
