# translation-service Specification

## Purpose
Other apps translate plain text through integriq: decidiq sends minutes to `TranslationService`, which passes them to a DeepL or LibreTranslate source an administrator enabled.

## Requirements

### Requirement: A sibling app can translate text through integriq (REQ-TRL-001)

Integriq MUST offer `OCA\Integriq\Service\TranslationService::translate(string $text, string $sourceLocale, string $targetLocale)` returning an array with `success`, `text`, `provider` and `message`. It MUST send the text to the first enabled translation source (`deepl-translation`, then `libretranslate`) in the request shape of that provider, and MUST return the provider's translated text. Equal locales and empty text MUST come back unchanged without a call.

#### Scenario: a clerk translates approved minutes into English
- GIVEN an administrator enabled the `deepl-translation` source with a DeepL key
- WHEN decidiq asks integriq to translate "De vergadering is geopend." from nl to en
- THEN integriq posts the text to DeepL's `/translate` with source NL and target EN, and decidiq receives "The meeting is open." with provider `deepl-translation`
- @e2e exclude service contract called by another app, no integriq screen; covered by PHPUnit `TranslationServiceTest`

### Requirement: An unconfigured instance says so (REQ-TRL-002)

When no translation source is enabled, or the provider answers without a translation, `translate()` MUST throw `TranslationUnavailableException` with a message naming what is missing, and MUST NOT return the original text as a success.

#### Scenario: nobody has set up a translation source
- GIVEN no enabled `deepl-translation` or `libretranslate` source
- WHEN decidiq asks integriq to translate a text
- THEN integriq throws "No translation source is enabled", no outside call is made, and decidiq keeps its own fallback
- @e2e exclude service contract called by another app, no integriq screen; covered by PHPUnit `TranslationServiceTest`

### Requirement: Translation providers are source templates (REQ-TRL-003)

Integriq MUST seed dormant source templates `deepl-translation` and `libretranslate` that validate against the `source` schema, and MUST list them in the catalogue under `Language`.

#### Scenario: an administrator finds the DeepL template
- GIVEN a fresh install
- WHEN the administrator opens the catalogue
- THEN "DeepL translation" and "LibreTranslate" are listed under Language, both disabled until a key or an instance URL is set
- @e2e exclude seed data; covered by PHPUnit `TranslationSourceTemplatesTest`
