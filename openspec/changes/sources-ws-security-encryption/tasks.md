# Tasks: sources-ws-security-encryption

Kind: code. Matrix row `integriq:src-ws-security`.

### Task 1: Encrypt and decrypt SOAP bodies with WS-Security
- **spec_ref**: openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
- **files**: `lib/Adapters/Digikoppeling/WsSecurityEncryptor.php`, `lib/Adapters/Digikoppeling/WusProfileService.php`, `lib/Service/SOAPService.php`, `tests/Unit/Adapters/Digikoppeling/WsSecurityEncryptorTest.php`
- **acceptance_criteria**:
  - GIVEN encryption on WHEN a call is sent THEN the body is encrypted for the partner certificate after signing
  - GIVEN an encrypted answer WHEN it arrives THEN it is decrypted before verification, and a wrong key fails the call with the reason
- [ ] Implement
- [ ] Test (PHPUnit)
