# Tasks: connectors-translation-service

Kind: code. Size S. Half for decidiq `min-12` (matrix row `integriq:con-translate`).

## Implementation tasks

### Task 1: The translation service
- **spec_ref**: `openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001`
- **files**: `lib/Service/TranslationService.php`, `lib/Exception/TranslationUnavailableException.php`
- **acceptance_criteria**:
  - GIVEN an enabled `deepl-translation` source WHEN `translate('Goedemorgen', 'nl', 'en')` runs THEN the service posts `{"text":["Goedemorgen"],"source_lang":"NL","target_lang":"EN"}` to `/translate` and returns the text DeepL answered
  - GIVEN an enabled `libretranslate` source WHEN the same call runs THEN it posts `q`, `source`, `target` and returns `translatedText`
- [x] Implement
- [x] Test (PHPUnit with a call log `ObjectEntity` as the call result, as CallService returns it)

### Task 2: No source, no fake answer
- **spec_ref**: `openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002`
- **files**: `lib/Service/TranslationService.php`
- **acceptance_criteria**:
  - GIVEN no enabled translation source WHEN `translate()` runs THEN `TranslationUnavailableException` is thrown and no call is made
- [x] Implement
- [x] Test (PHPUnit)

### Task 3: Source templates
- **spec_ref**: `openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-translation-providers-are-source-templates-req-trl-003`
- **files**: `lib/Settings/register.d/deepl-translation-source.json`, `lib/Settings/register.d/libretranslate-source.json`, `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN the seed fragments WHEN they are validated against the `source` schema THEN both pass, and both are disabled
- [x] Implement
- [x] Test (PHPUnit that validates each fragment object against the real `source` schema in `lib/Settings/integriq_register.json`)

## Verification
- [x] The container resolves `OCA\Integriq\Service\TranslationService` (the name decidiq looks up); proved by `TranslationServiceTest::testTheClassDecidiqLooksUpExistsAndAutowires`
