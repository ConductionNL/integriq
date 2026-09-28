---
kind: code
depends_on: []
---

# Proposal: connectors-translation-service

## Summary

Decidiq already asks integriq to translate minutes, and nothing answers. Its `LogTranslationAdapter` looks up `OCA\Integriq\Service\TranslationService` (or `TranslationSourceService`) in the container and calls `translate($text, $sourceLocale, $targetLocale)`; neither class exists, so every request falls back to the dormant log adapter and the minutes come back untranslated. This change ships that service, backed by a source an administrator points at DeepL or LibreTranslate.

## Why

Matrix row `integriq:con-translate`, "Translate a text through an outside translation service", sibling row `decidiq:min-12` ("Translate minutes into another language", state building in decidiq). Decided `build` in the build-all pass of 2026-09-28: the consumer is already written and shipped, so the missing half is a defect a sibling exposes, not a speculative feature.

The decidiq note on `min-12`: "Queue and adapter plumbing exist, but the bound adapter translates nothing unless an integriq translation service answers, which I could not find."

Competitor cells from the matrix:

- n8n `yes`: "packages/nodes-base/nodes/DeepL/DeepL.node.ts:63 'translate' and :45 'language' resource, plus packages/nodes-base/nodes/Google/Translate and packages/nodes-base/nodes/LingvaNex nodes".
- apisix `partial`: translation only as an LLM prompt through `apisix/plugins/ai-request-rewrite.lua:60`.
- mulesoft `partial`: no translation connector on Exchange, only an LLM connector with a prompt the developer writes.

## What integriq already has

- `CallService::call()` (`lib/Service/CallService.php:3012`) calls any source, with its auth, logging and rate limits.
- `ConnectionStore::findSourceBySlug()` (`lib/Service/ConnectionStore.php:228`) reads a source by slug in system context.
- Seeded source templates in `lib/Settings/register.d/*-source.json`, listed in the catalogue by `CatalogRegistryService`.

## What this change builds

1. `OCA\Integriq\Service\TranslationService::translate(string $text, string $sourceLocale, string $targetLocale): array` with the return shape decidiq reads (`success`, `text`, `message`, `provider`).
2. Two dormant source templates, `deepl-translation` and `libretranslate`, listed in the catalogue under `Language`.
3. A clear failure when no translation source is enabled, so the caller keeps its own fallback.

## Out of scope

- A translation screen inside integriq. The consumer app owns the screen.
- Translating documents or files; this is plain text.
- Nextcloud's own `ITranslationManager` providers; those live in the platform and are a different path.

## Impact

- New: `lib/Service/TranslationService.php`, `lib/Exception/TranslationUnavailableException.php`, two seed fragments, one catalogue category entry per slug.
- No schema change and no migration.

## Cross-project dependencies

- decidiq `LogTranslationAdapter::OPENCONNECTOR_SERVICES` names `Service\TranslationService`. Renaming the class breaks decidiq silently, so the name is part of the contract.
