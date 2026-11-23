// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Fetch Vue and the Synced-from app, as one lazily loaded chunk.
 *
 * Kept out of integration.js, which runs on every Nextcloud page: this chunk
 * is only requested when a host mounts the leaf.
 *
 * @return {Promise<{createApp: import('vue').CreateAppFunction<Element>, SyncedFromTab: object}>} Vue's createApp and the app component.
 */
export async function loadSyncedFromApp() {
	const [{ createApp }, { default: SyncedFromTab }] = await Promise.all([
		import(/* webpackChunkName: "integration-app" */ 'vue'),
		import(/* webpackChunkName: "integration-app" */ './SyncedFromTab.vue'),
	])
	return { createApp, SyncedFromTab }
}
