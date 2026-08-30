# Retrofit — http-call-engine

Describes observed behavior of 15 methods under `http-call-engine` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

CallService.php (11 methods):

- `lib/Service/CallService.php::call()`
- `lib/Service/CallService.php::calculateExpires()`
- `lib/Service/CallService.php::renderValue()`
- `lib/Service/CallService.php::renderConfiguration()`
- `lib/Service/CallService.php::decideMethod()`
- `lib/Service/CallService.php::writeFile()`
- `lib/Service/CallService.php::removeFile()`
- `lib/Service/CallService.php::removeFiles()`
- `lib/Service/CallService.php::getCertificate()`
- `lib/Service/CallService.php::sourceRateLimit()`
- `lib/Service/CallService.php::applyConfigDot()`

SOAPService.php (4 methods):

- `lib/Service/SOAPService.php::setupEngine()`
- `lib/Service/SOAPService.php::callSoapSource()`
- `lib/Service/SOAPService.php::getSoapVersion()`
- `lib/Service/SOAPService.php::parseDynamicXsd()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (synchronous source-save in
  rate-limit reset; mid-flight Source write to OR; XXE exposure window in SOAP via
  `libxml_set_external_entity_loader` that returns `$system` unguarded;
  PdokConnector-style hard-coded XML-element names; secrets-stripping via
  `str_contains('authentication')` substring match)

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
