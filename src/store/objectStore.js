// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * OpenConnector object store instance.
 *
 * Created via `createObjectStore` (@conduction/nextcloud-vue >= beta.212) so
 * the liveUpdatesPlugin is installed default-on: the store exposes
 * `subscribe(type, id?)` / `unsubscribe(handle)` backed by notify_push with a
 * visibility-gated polling fallback. The plugin is inert until the first
 * `subscribe()` call, so plain CRUD behaviour is identical to the package's
 * shared `useObjectStore` this module replaces for the bespoke detail pages.
 *
 * The package's shared `useObjectStore` instance is created without plugins
 * and therefore has no `subscribe()` — the bespoke detail pages (Rule /
 * Synchronization / Mapping) import from this module instead so fetch, save,
 * and live-update refetches all land in the same store instance. The generic
 * manifest `index`/`detail` pages keep resolving the shared instance inside
 * CnPageRenderer; adopting live updates there is renderer-side work in nc-vue.
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
import { createObjectStore } from '@conduction/nextcloud-vue'

export const useObjectStore = createObjectStore('openconnector-objects')
