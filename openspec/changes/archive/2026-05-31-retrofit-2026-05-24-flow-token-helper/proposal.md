# Retrofit — flow-token-helper

Describes observed behavior of 21 methods under `flow-token-helper` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/Helper/FlowToken.php::__construct()`
- `lib/Service/Helper/FlowToken.php::setRequestOriginal()` + `::getRequestOriginal()`
- `lib/Service/Helper/FlowToken.php::setRequestAmended()` + `::getRequestAmended()`
- `lib/Service/Helper/FlowToken.php::setResponseOriginal()` + `::getResponseOriginal()`
- `lib/Service/Helper/FlowToken.php::setResponseAmended()` + `::getResponseAmended()`
- `lib/Service/Helper/FlowToken.php::setSyncInputOriginal()` + `::getSyncInputOriginal()`
- `lib/Service/Helper/FlowToken.php::setSyncInputAmended()` + `::getSyncInputAmended()`
- `lib/Service/Helper/FlowToken.php::setSyncOutputOriginal()` + `::getSyncOutputOriginal()`
- `lib/Service/Helper/FlowToken.php::setSyncOutputAmended()` + `::getSyncOutputAmended()`
- `lib/Service/Helper/FlowToken.php::getHeaders()` (private helper)
- `lib/Service/Helper/FlowToken.php::getRawContent()` (private helper)
- `lib/Service/Helper/FlowToken.php::looksLikeXml()` (private helper)
- `lib/Service/Helper/FlowToken.php::parseContent()` (private helper)
- `lib/Service/Helper/FlowToken.php::__serialize()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section + design.md surface a HIGH-severity XXE finding in `parseContent` + `looksLikeXml` (simplexml_load_string without LIBXML_NONET)
- Cap REQs at 5: fold 8 identical paired get/set methods (sync I/O × original/amended) under one REQ; one REQ each for the request/response shape conversion + __serialize

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
