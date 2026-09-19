---
kind: code
depends_on: []
---

# Proposal: allowlisted-expression-sources

## Summary

An expression language that can read the environment is a data exfiltration
path. The allowlist is the answer, and it belongs where the reach outside the
instance already lives. Integriq offers a prefixed value source behind one
contract, with `env:` resolving only what an administrator named.
OpenRegister keeps the expression language itself.

## Motivation

Two sources of record name the same capability, and they do not agree on
which cluster it sits in. Both are cited here so the ownership is arguable
rather than assumed.

**The depth study**, `procest/_round4/discovery/casetype-configurability.md`
in ConductionNL/market-intelligence, 2026-09-14, second read, the
twenty-two capabilities the 54 rows do not cover:

> D-casetype-20, area B, "Environment-variable access from an expression,
> behind an allowlist". Relevance: "low, but it is the shape that keeps an
> expression language safe". Passer: Valtimo (`V-vr`). dossiq: unread.

Its neighbour is the reason it matters. D-casetype-19, "One expression
language across every data source, readable and writable", is rated "high,
the second read calls it the single most reusable idea in Valtimo". The study
lists the eleven Valtimo value-resolver prefixes, `doc:`, `case:`, `pv:`,
`task:`, `bb:`, `iko:`, `zaak:`, `zaakstatus:`, `zaakresultaat:`, `zaakobject:`
and unprefixed literals, and records the twelfth:

> `procest/valtimo/specs/value-resolvers/spec.md` adds a twelfth, `env:`,
> described as "Environment variable (requires whitelisting)", and states
> that the interface runs both ways: `resolve(expression, context)` and
> `store(expression, value, context)`.

**The consolidated candidate list**, `_round4/discovery/candidates.json`:

| candidate | relevance | dossiq | driven | cluster and owner |
|---|---|---|---|---|
| C-access-and-privacy-40, "Allowlist for environment variables in expressions" | should | no | valtimo | 4 "Security hardening of the instance", owner openregister |

Its clause, verbatim (`access-and-privacy.tsv:75`): "an expression language
that can read the environment is a data exfiltration path, and the allowlist
is the answer". Its evidence: "valtimo: Value resolvers
(value-resolvers/spec.md)", source D-valtimo-48.

## Why integriq, when the consolidated candidate sits in an openregister cluster

The consolidator put C-access-and-privacy-40 in cluster 4 with seventeen
other hardening candidates, and cluster 4's owner is openregister. That is
the right home for the hardening half. It is not the right home for the
source.

The depth study states the ownership rule that decides it: "A property
definition, a code list, a rule or a computed value belongs to openregister's
schema and rules abstractions. A form belongs to buildiq or Nextcloud Forms.
**A registry lookup belongs to integriq.** dossiq owns the case-type editor
surface."

An expression prefix that reads outside the instance is a lookup, not a rule.
`env:` is the first of them and the smallest, which is exactly why it is the
one to specify the contract against. Decision D3 keeps the engine with
openregister: "option 2 for the engine", meaning `field-rules-by-state`,
`lifecycle-declarative-conditions` and the JSON-AST `CalculationEvaluator`.
This change adds no evaluator, no operator and no syntax.

So the split is: openregister owns the language and the hardening of cluster
4. Integriq owns the sources a prefix reaches, and the allowlist that decides
which of them may be read.

## What integriq builds and what dossiq consumes

Integriq builds the resolver registry and the allowlist. dossiq consumes
nothing new: an expression in a case type is evaluated by openregister, which
asks integriq for a prefixed value the same way it asks for a property
source.

## The existing specs this extends

- `registry-backed-field-source`, REQ-RFS-001 and REQ-RFS-003: the provider
  contract discovered through a DI tag, and the provenance a resolved value
  carries. A prefixed value source is the same shape with a prefix instead of
  a property declaration.
- `execution-trace`, REQ-003: snapshot redaction before any step is buffered,
  which is what keeps a resolved secret out of a trace.
- `rule-pipeline` and `flow-token-helper`: the places an expression is
  evaluated in integriq today, which read the same registry rather than
  growing their own.
- `authentication-twig` and `migrate-inline-secrets-to-broker`: the existing
  rule that a secret is referenced rather than inlined.

## Size and dependencies

Size S. D-casetype-20's own relevance is "low, but it is the shape that keeps
an expression language safe". It waits on nothing, and it is cheapest to
build before any second prefix exists rather than after.

## Out of scope

- The expression language, its operators and its evaluator. Openregister,
  decision D3.
- The other seventeen candidates of cluster 4, "Security hardening of the
  instance". Openregister.
- Registry-backed property values, which `registry-backed-field-source`
  already specifies under decision D2.
- Writing back through an expression. The Valtimo interface has a `store`
  half; this change declares whether a source supports it and specifies no
  writer.
