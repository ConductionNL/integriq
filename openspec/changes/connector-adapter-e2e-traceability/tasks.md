## 1. Annotate stuf-adapter (58 scenarios, zero @e2e today)

- [ ] 1.1 Add one `@e2e exclude backend StUF-BG/StUF-ZKN integration — covered by PHPUnit, not browser UI` line under every `#### Scenario:` in `openspec/specs/stuf-adapter/spec.md` (mirror the exact wording/placement pattern used in `openspec/specs/endpoint-runtime/spec.md`)
- [ ] 1.2 Confirm `find src -iname "*stuf*"` still returns nothing (no Vue UI exists) so the `exclude` reason remains true before committing it

## 2. Annotate ibabs-notubiz-connector (46 scenarios, zero @e2e today)

- [ ] 2.1 Add one `@e2e exclude backend iBabs/NotuBiz RIS integration — covered by PHPUnit, not browser UI` line under every `#### Scenario:` in `openspec/specs/ibabs-notubiz-connector/spec.md`
- [ ] 2.2 Confirm `find src -iname "*ibabs*" -o -iname "*notubiz*"` still returns nothing before committing

## 3. Annotate the dso-omgevingsloket scenarios not owned by the in-flight signature-verification change

- [ ] 3.1 Add `@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI` to every scenario in `openspec/specs/dso-omgevingsloket/spec.md` EXCEPT the REQ-DSO-050 scenarios owned by `openspec/changes/dso-stam-pkioverheid-signature-verification/`
- [ ] 3.2 Leave the REQ-DSO-050 scenarios for that change to annotate once its verifier lands (do not pre-empt its wording)

## 4. Close the STUF auth zero-coverage gap (security-relevant path)

- [ ] 4.1 Locate the current mTLS certificate handling for StUF sources (`CallService::getCertificate()` / `removeFiles()`) and the WS-Security header builder in `AuthenticationService`; confirm the auth-type name used for StUF WS-Security config
- [ ] 4.2 Add PHPUnit coverage (new file or extend existing service test) for REQ-STUF-011: certificate written to temp file for a StUF source with PKIoverheid cert configured, escaped-`\n` PEM converted to real newlines, temp file removed after the request completes (success and exception paths)
- [ ] 4.3 Add PHPUnit coverage for REQ-STUF-012: `wsse:Security`/`wsse:UsernameToken` header present with username; `PasswordDigest` mode produces `Base64(SHA1(Nonce + Created + Password))` (assert against a hand-computed fixture value, not just "is truthy"); `PasswordText` mode includes the plaintext password
- [ ] 4.4 If either test reveals the implementation does not match the spec's stated formula/behavior, STOP — do not silently "fix" it in this change; file it as a new tracked gap instead (per `feedback_always-file-issues.md`) and note it in this change's proposal.md before merging
- [ ] 4.5 Run `composer check:strict` and the full PHPUnit suite; confirm no regressions

## 5. Validate

- [ ] 5.1 `openspec validate connector-adapter-e2e-traceability --strict`
- [ ] 5.2 Spot-check gate-19's `check_e2e_coverage.py` logic still parses the new `@e2e exclude` lines correctly (same format as `endpoint-runtime`)
