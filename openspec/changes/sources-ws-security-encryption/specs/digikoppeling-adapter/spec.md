# digikoppeling-adapter Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-ws-security-encryption

## Purpose

A SOAP source can encrypt its messages with WS-Security and read encrypted answers. Row `integriq:src-ws-security`.

## ADDED Requirements

### Requirement: A SOAP source encrypts its body and decrypts the answer (REQ-WSE-001)

A SOAP source MUST be able to switch on WS-Security encryption. When it is on, integriq MUST encrypt the SOAP body with XML Encryption for the partner's certificate held on the source, after signing it, and MUST decrypt an encrypted answer with the organisation's private key from the credential broker before it verifies the signature. An answer that cannot be decrypted MUST fail the call with the reason, and MUST NOT be passed on as an empty body.

#### Scenario: a partner that demands encryption receives an encrypted body
- GIVEN a SOAP source with encryption on and the partner's certificate set
- WHEN integriq calls it
- THEN the SOAP body on the wire is an `EncryptedData` element and the signature still verifies after decryption

#### Scenario: an answer that cannot be decrypted fails the call
- GIVEN an encrypted answer for a different key
- WHEN integriq receives it
- THEN the call fails naming the decryption error and nothing is mapped
