---
sidebar_position: 3
title: Create a mapping
description: Define how an external object shape is transformed into the shape OpenRegister (or another target) expects.
---

# Create a mapping

A **Mapping** describes how to transform data from one shape into another. Mappings sit between sources and targets in a synchronization flow, and are also used by endpoints to reshape request and response bodies. The mapping engine supports direct field paths, Twig template expressions, type casts, and JSON Logic conditions.

## Goal

By the end you will have created a Mapping that takes objects in the shape your source returns and turns them into the shape your target schema expects, and you will have validated the mapping with a sample payload.

## Prerequisites

- You have completed [Add a source](./02-add-source.md).
- You know the shape of the data your source returns (one example payload is enough).
- You know the shape of the target object — usually a schema in OpenRegister.

## Steps

1. From the navigation, click **Mappings** and then **Add Mapping**.

   ![Mappings list with Add Mapping button](/screenshots/tutorials/user/03-create-mapping-01.png)

2. Give the mapping a clear **Name** and **Slug** (the slug is how syncs and endpoints reference it). Save to land on the detail view.

   ![Add Mapping dialog](/screenshots/tutorials/user/03-create-mapping-02.png)

3. Open the **Mapping** tab. Add field assignments one by one. For each target field, decide between a direct dot-notation reference (`input.naam`) and a Twig expression (`{{ input.startdatum | date('Y-m-d') }}`). Twig is needed whenever you reshape values, concatenate strings, or call a filter.

   ![Mapping field editor](/screenshots/tutorials/user/03-create-mapping-03.png)

4. If the source returns strings where the target expects integers, dates or JSON, open the **Cast** tab and declare the cast (`bouwkosten: integer`, `metadata: jsonToArray`, …). Casts run after the mapping pass.

   ![Mapping cast editor](/screenshots/tutorials/user/03-create-mapping-04.png)

5. Open the **Test** action. Paste a sample source payload, run the mapping, and review the transformed output side-by-side. Iterate on the field assignments until the output matches the target shape.

   ![Mapping test pane](/screenshots/tutorials/user/03-create-mapping-05.png)

## Verification

You are done when: the test pane produces an output that matches the target schema's expected shape, all required target fields are populated, and casts apply where you expected them to.

## Common issues

| Symptom | Fix |
|---|---|
| Test output has empty strings for fields that should have values | The dot-notation path doesn't match the source structure — print the raw `input` object first (`{{ input \| json_encode }}`) to inspect the real path. |
| Twig expression throws "filter does not exist" | The filter name is misspelled or the filter requires arguments — see the Mappings reference for the supported filter set. |
| Cast doesn't apply | The cast field path is computed from a Twig expression instead of a static path — casts only run on statically-resolvable paths. |

## Reference

- [Mappings feature](../../features/mappings.md) — full reference for the mapping engine.
- [Mappings — strategies](../../features/mappings.md#mapping-strategies) — direct reference, Twig, dot-notation, JSON Logic.
