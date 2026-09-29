# Design: connectors-translation-service

Kind: code. Size S. Read at integriq development `dc38add0` on 2026-09-28, decidiq development on the same day.

## Context

- **The caller.** decidiq `lib/Service/LogTranslationAdapter.php` resolves `FleetAppId::getService($container, 'integriq', 'Service\TranslationService')`, calls `translate($text, $sourceLocale, $targetLocale)` when the method exists, accepts either a non-empty string or an array with `text` (and optional `success`, `message`), and on any `Throwable` logs a warning and returns the original text with provider `log`. Locales are ISO 639-1.
- **No translation code.** `grep -rniE 'deepl|libretranslate|TranslationService' lib src` finds nothing; `ZgwVersionTranslationService` translates ZGW API versions, not text.
- **Calling a source.** `CallService::call(ObjectEntity $source, string $endpoint, string $method, array $config)` returns a `CallLog` whose `getResponse()['body']` holds the raw body.
- **Finding a source.** `ConnectionStore::findSourceBySlug(string $slug)` reads register `integriq`, schema `source`, in system context (`source` is admin-only per `99-source-lockdown.json`).

## D1. One service, two provider shapes

`TranslationService` holds an ordered list of template slugs: `deepl-translation`, then `libretranslate`. It uses the first one that exists and has `isEnabled` true. The source's `configuration.translationProvider` (`deepl` or `libretranslate`) picks the request and response shape:

| Provider | Request | Answer |
|---|---|---|
| deepl | `POST /translate`, JSON `{"text": [<text>], "source_lang": "NL", "target_lang": "EN"}` | `translations[0].text` |
| libretranslate | `POST /translate`, JSON `{"q": <text>, "source": "nl", "target": "en", "format": "text"}` | `translatedText` |

DeepL wants upper-case language codes; LibreTranslate wants lower case. The service normalises both.

## D2. The answer shape decidiq reads

`translate()` returns `['success' => true, 'text' => <translated>, 'provider' => <slug>, 'message' => 'Translated through <source name>.']`. Equal locales return the text untouched without a call. Empty text returns empty text without a call.

## D3. No source means an exception, not a fake success

When no enabled translation source exists, or the provider answers without a translation, the service throws `TranslationUnavailableException` with a message naming what is missing. decidiq catches it and keeps its log fallback, so an unconfigured instance behaves exactly as it does today, and the log says why.

## D4. Templates

`lib/Settings/register.d/deepl-translation-source.json` (location `https://api-free.deepl.com/v2`, header `Authorization: DeepL-Auth-Key <key>` through the source's credential) and `libretranslate-source.json` (location empty, the administrator sets the instance URL). Both ship `isEnabled: false`. `CatalogRegistryService::SLUG_CATEGORY_OVERRIDES` gains both under `Language`.

## Declarative versus imperative

The templates are configuration. The service is code, because the caller resolves a class by name.
