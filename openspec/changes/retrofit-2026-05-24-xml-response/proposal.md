# Retrofit — xml-response

Describes observed behavior of 7 methods under `xml-response` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Http/XMLResponse.php::getData()`
- `lib/Http/XMLResponse.php::setRenderCallback()`
- `lib/Http/XMLResponse.php::render()`
- `lib/Http/XMLResponse.php::arrayToXml()`
- `lib/Http/XMLResponse.php::buildXmlElement()` (private helper of arrayToXml)
- `lib/Http/XMLResponse.php::createChildElement()` (private helper of buildXmlElement)
- `lib/Http/XMLResponse.php::createSafeTextNode()` (private helper of createChildElement)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section + design.md surface observed-but-suspicious behaviour: double `html_entity_decode` in `createSafeTextNode` (potential entity-unwrap on attacker-controlled text); IQueryBuilder special-case looks like ad-hoc patching; render-callback bypasses the safe path entirely

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
