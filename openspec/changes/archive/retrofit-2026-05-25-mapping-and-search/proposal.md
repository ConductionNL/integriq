---
kind: spec
---

# Retrofit — mapping-and-search

Describes observed behavior of 24 methods under `mapping-and-search` as 5 new REQs. Code
already exists — this change retroactively specifies it. New capability `mapping-and-search`.

## Affected code units

- lib/Service/MappingService.php::executeMapping()
- lib/Service/MappingService.php::executeMappingLocal()
- lib/Service/MappingService.php::renderTemplateString()
- lib/Service/MappingService.php::encodeArrayKeys()
- lib/Service/MappingService.php::handleCast()
- lib/Service/MappingService.php::areAllArrayKeysNull()
- lib/Service/MappingService.php::coordinateStringToArray()
- lib/Service/MappingService.php::getMapping()
- lib/Service/MappingService.php::getMappings()
- lib/Controller/MappingsController.php::test()
- lib/Controller/MappingsController.php::saveObject()
- lib/Controller/MappingsController.php::getObjects()
- lib/Service/SearchService.php::search()
- lib/Service/SearchService.php::mergeFacets()
- lib/Service/SearchService.php::mergeAggregations()
- lib/Service/SearchService.php::sortResultArray()
- lib/Service/SearchService.php::createMongoDBSearchFilter()
- lib/Service/SearchService.php::createMySQLSearchConditions()
- lib/Service/SearchService.php::unsetSpecialQueryParams()
- lib/Service/SearchService.php::createMySQLSearchParams()
- lib/Service/SearchService.php::createSortForMySQL()
- lib/Service/SearchService.php::createSortForMongoDB()
- lib/Service/SearchService.php::parseQueryString()
- lib/Service/SearchService.php::recursiveRequestQueryKey()

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior: `SearchService::search()` calls
  undefined properties (will fatal at runtime); mapping endpoints are `@NoAdminRequired`;
  the `date` cast is a PHP `date()` passthrough

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
