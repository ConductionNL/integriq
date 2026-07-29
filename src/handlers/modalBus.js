// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Tiny event-bus singleton used by the manifest-driven row-action handlers
// (src/handlers/actionHandlers.js) to ask the app shell to open a richer modal
// (Test mapping, Add endpoint rule, …) without leaking the manifest-renderer's
// internal `cnOpenModal` inject out to plain functions that have no Vue
// instance context.
//
// Vue 3 (ADR-066): Vue 3 removed the instance event-emitter API ($on/$off/
// $once), so the former `new Vue()` bus no longer works. We use `mitt` — a
// ~200-byte framework-agnostic emitter — with the SAME symmetric contract:
// emitters call `modalBus.emit('open-<name>', ctx)` and the host component
// (src/modals/v2/ModalHost.vue) subscribes once per known event name via
// `modalBus.on(...)` / `modalBus.off(...)`. ModalHost owns the modal
// components — handlers never instantiate Vue components themselves.
//
// Kept deliberately minimal: no priority queue, no async ack, no multi-target
// broadcast. If a second modal needs to open while one is already up, ModalHost
// handles the layering itself (each modal manages its own NcModal `show` flag).

import mitt from 'mitt'

/**
 * Shared emitter used purely as an `emit`/`on`/`off` hub. Exported as a
 * singleton so any module that imports the file gets the same dispatcher —
 * there is no per-app or per-route scoping.
 *
 * @type {import('mitt').Emitter<Record<string, unknown>>}
 */
export const modalBus = mitt()

/**
 * Known event names. Centralised here so a `grep modalBus.EVENT_` over
 * the codebase surfaces every producer and consumer.
 */
export const EVENT_OPEN_TEST_MAPPING = 'open-test-mapping'
export const EVENT_OPEN_ADD_ENDPOINT_RULE = 'open-add-endpoint-rule'
export const EVENT_OPEN_SUBSCRIPTION_SIGNING = 'open-subscription-signing'
// connector-catalog-ui: Catalog detail dialog + configuration import/export dialogs.
export const EVENT_OPEN_CATALOG_ITEM_DETAIL = 'open-catalog-item-detail'
export const EVENT_OPEN_CONFIGURATION_IMPORT = 'open-configuration-import'
export const EVENT_OPEN_CONFIGURATION_EXPORT = 'open-configuration-export'
// environments-and-promotion: promote-configuration flow modal.
export const EVENT_OPEN_PROMOTION = 'open-promotion'
