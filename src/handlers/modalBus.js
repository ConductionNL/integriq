// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Tiny Vue 2 event-bus singleton used by the manifest-driven row-action
// handlers (src/handlers/actionHandlers.js) to ask the app shell to open
// a richer modal (Test mapping, Add endpoint rule, …) without leaking
// the manifest-renderer's internal `cnOpenModal` inject out to plain
// functions that have no Vue instance context.
//
// The bus is symmetric: emitters call `modalBus.$emit('open-<name>', ctx)`
// and the host component (src/modals/v2/ModalHost.vue) listens once per
// known event name and toggles its local visibility state. ModalHost
// owns the modal components — handlers never instantiate Vue components
// themselves.
//
// Kept deliberately minimal: no priority queue, no async ack, no
// multi-target broadcast. If a second modal needs to open while one is
// already up, ModalHost handles the layering itself (each modal manages
// its own NcModal `show` flag).

import Vue from 'vue'

/**
 * Shared Vue 2 instance used purely as a $emit/$on hub. Exported as a
 * singleton so any module that imports the file gets the same
 * dispatcher — there is no per-app or per-route scoping.
 *
 * @type {Vue}
 */
export const modalBus = new Vue()

/**
 * Known event names. Centralised here so a `grep modalBus.EVENT_` over
 * the codebase surfaces every producer and consumer.
 */
export const EVENT_OPEN_TEST_MAPPING = 'open-test-mapping'
export const EVENT_OPEN_ADD_ENDPOINT_RULE = 'open-add-endpoint-rule'
export const EVENT_OPEN_SUBSCRIPTION_SIGNING = 'open-subscription-signing'
