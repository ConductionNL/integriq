// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * liveObjectSubscription — Options-API mixin for per-object live updates.
 *
 * Wraps `objectStore.subscribe(type, uuid)` (liveUpdatesPlugin, default-on in
 * createObjectStore since nc-vue beta.212) with the reference guard set from
 * OpenRegister's ObjectDetails view:
 *
 * - pending-key marker: a subscribe already in flight for the same
 *   (type, uuid) is never doubled (that would leak the first handle),
 * - epoch counter: a release during an in-flight subscribe (object switch /
 *   destroy) makes the resolution unsubscribe itself instead of leaking,
 * - `beforeDestroy` releases the active handle and bridge watcher.
 *
 * Events are refetch hints only: the plugin re-runs fetchObject(type, uuid)
 * through the store, landing in `objects[type][uuid]`. When the consuming
 * component defines an `applyLiveObject(fresh)` method, a bridge watcher on
 * that cache slot calls it with the fresh object (components that render the
 * cache directly, like MappingDetailPage, need no bridge — reactivity does
 * the rest). Components that hold a local draft MUST dirty-guard inside
 * `applyLiveObject` so a live refetch never clobbers unsaved edits.
 *
 * Uses notify_push when available, visibility-gated polling otherwise.
 * Subscribe failures are warn-logged and never surface as render errors.
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
import { useObjectStore } from '../store/objectStore.js'

export default {
	data() {
		return {
			liveHandle: null,
			liveKey: '',
			livePendingKey: '',
			liveEpoch: 0,
			liveUnwatch: null,
		}
	},

	/**
	 * Lifecycle hook: release the live object subscription on unmount.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	beforeDestroy() {
		this.releaseLiveSubscription()
	},

	methods: {
		/**
		 * Subscribe to live updates for one object (or-object-{uuid}).
		 * Idempotent per (type, uuid); releases the previous subscription
		 * when the scope changes. The type must already be registered on
		 * the openconnector object store (the detail pages register it
		 * before their first fetch).
		 *
		 * @param {string} type Registered object type slug (e.g. 'rule')
		 * @param {string} uuid The object identifier used by fetchObject
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		async syncLiveSubscription(type, uuid) {
			const store = useObjectStore()
			if (typeof store.subscribe !== 'function' || !type || !uuid) {
				return
			}
			const key = `${type}::${uuid}`
			if (this.liveHandle && this.liveKey === key) {
				return
			}
			if (this.livePendingKey === key) {
				// A subscribe for this exact object is already in flight —
				// re-subscribing here would leak the first handle + watcher.
				return
			}
			this.releaseLiveSubscription()
			try {
				const epoch = this.liveEpoch
				this.livePendingKey = key
				this.liveKey = key
				const handle = await store.subscribe(type, uuid)
				if (this.livePendingKey === key) {
					this.livePendingKey = ''
				}
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (another object opened, or the
					// component was destroyed) — drop the stale subscription.
					store.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
				if (typeof this.applyLiveObject === 'function') {
					// Bridge: event → plugin refetch → objects[type][uuid]
					// cache → component-local state (draft/pristine).
					this.liveUnwatch = this.$watch(
						() => store.getObject(type, uuid),
						(fresh) => {
							if (fresh && this.liveKey === key) {
								this.applyLiveObject(fresh)
							}
						},
					)
				}
			} catch (e) {
				if (this.livePendingKey === key) {
					this.livePendingKey = ''
				}
				this.liveHandle = null
				this.liveKey = ''
				console.warn('[liveObjectSubscription] subscribe failed:', e?.message ?? e)
			}
		},

		/**
		 * Release the current live subscription and its bridge watcher, and
		 * invalidate any in-flight subscribe (its resolution unsubscribes
		 * itself via the epoch check).
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingKey = ''
			if (this.liveUnwatch) {
				this.liveUnwatch()
				this.liveUnwatch = null
			}
			const store = useObjectStore()
			if (this.liveHandle && typeof store.unsubscribe === 'function') {
				store.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
			this.liveKey = ''
		},
	},
}
