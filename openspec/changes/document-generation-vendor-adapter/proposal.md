---
kind: code
---

# Proposal: document-generation-vendor-adapter

## Summary

One integriq provider seam for vendor document generation services,
SmartDocuments and Xential first, with a log binding for development.
Filinq stays the fleet's one document channel (ADR-075) and calls the seam
as a template backend when a municipality has bought a vendor. A case app
never talks to the vendor; it keeps calling filinq.

## Motivation

Competitor gap register, row 12.11 "External document generation service
(SmartDocuments, Xential)" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
integriq, size M, statutory. Opened by the small-owner lane of the
OpenSpec phase. No competitor scores yes on the row (register `best`
column), and the row is statutory: a beschikking has to come out of the
template engine the municipality has licensed and filled with its legal
texts, and many have licensed SmartDocuments or Xential.

dossiq has `lib/Service/Beschikking/TemplateEngineAdapterInterface.php`
and ships only `MockTemplateEngineAdapter.php` (register note). Filinq
renders Twig templates through its own engine (`document-creatie-sjablonen`,
`pdf-generation`) and ADR-075 makes it the one owner and one channel of
document generation for the fleet. A vendor API is neither dossiq's nor
filinq's to embed: it is an external system with its own credentials, and
those are integriq's (ADR-091 decision 1, ADR-067). Integriq already
ships the pattern for exactly this shape: `DigitalPostProviderInterface`
with `log`, `berichtenbox` and `postex` bindings
(`berichtenbox-digital-post-adapter`), and the klantinteracties providers
before it.

## Scope

- `DocumentGenerationProviderInterface` with `listTemplates`, `render`,
  `status` and `fetch`, resolved by `providerId` from the source
  configuration. Bindings: `log` (development, answers a placeholder
  PDF), `smartdocuments` (REST, template selection by the vendor's
  template id, data as the vendor's XML or JSON envelope), `xential`
  (REST, the same shape over Xential's API).
- A `documentGenerationJob` object per render: the source, the template,
  a hash of the data, the result file, a lifecycle `queued`, `rendered`,
  `failed`. The data itself is not stored; the job records what was sent
  by hash so an audit can match a beschikking to a render without
  integriq holding a second copy of the case.
- Typed events per ADR-041: `DocumentRenderRequestedEvent` in, with a
  result slot carrying the job id or a structured refusal;
  `DocumentRenderedEvent` out with the result file reference.
- Credentials by reference through the OpenRegister credential broker,
  never as raw values in a call.
- A catalog entry per binding (`connector-catalog`), dormant until a
  source is configured.

## How dossiq consumes it

The register's dossiq half: "TemplateEngineAdapterInterface bound to the
connector or to filinq; drop the mock". With ADR-075 the answer is filinq:
dossiq binds `TemplateEngineAdapterInterface` to filinq's document
generation contract and deletes `MockTemplateEngineAdapter`. Filinq,
in turn, offers a template `engine` per template: its own Twig engine or
a vendor source, and for a vendor source dispatches
`DocumentRenderRequestedEvent` and files the result. So the vendor is
one hop behind filinq, and dossiq's binding is the same whether the
municipality bought a vendor or not. dossiq's task lives in its umbrella
`competitor-parity-2026-09`; filinq's `engine` per template is a small
follow-up on `document-creatie-sjablonen`, named in the tasks here.

## ADRs

- ADR-075: filinq owns generation, one channel; this seam sits behind it.
- ADR-091 and ADR-067: a vendor API with credentials belongs to integriq.
- ADR-041: the seam is typed events with result slots.
- ADR-022: filinq consumes the connector; it embeds no vendor client.
- ADR-064: credentials by reference through the broker.

## Existing specs it extends

`connector-catalog` (the catalog entries) and the delta
`digital-post-adapter` (the provider pattern it copies).

## Out of scope

- Template authoring in the vendor. The vendor's own editor stays the
  vendor's; integriq lists templates, it does not edit them.
- Rendering filinq's Twig templates. That is filinq's engine and stays
  there.
- A vendor without a REST API. SmartDocuments and Xential both have one;
  a SOAP-only vendor is a later binding on the same interface.
