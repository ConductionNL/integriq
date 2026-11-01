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
 * - `beforeUnmount` releases the active handle and bridge watcher.
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
	beforeUnmount() {
		this.releaseLiveSubscription()
	},

	methods: {
		/**
		 * Subscribe to live updates for one object (or-object-{uuid}).
		 * Idempotent per (type, uuid); releases the previous subscription
		 * when the scope changes. The type must already be registered on
		 * the integriq object store (the detail pages register it
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
			const scope = { store, type, uuid, key: `${type}::${uuid}`, epoch: 0 }
			if (this.isLiveScopeCurrent(scope.key)) {
				return
			}
			this.releaseLiveSubscription()
			try {
				scope.epoch = this.liveEpoch
				this.livePendingKey = scope.key
				this.liveKey = scope.key
				this.adoptLiveHandle(scope, await store.subscribe(type, uuid))
			} catch (e) {
				this.dropFailedLiveSubscription(scope.key, e)
			}
		},

		/**
		 * Whether the subscription for this key is already active or in flight.
		 *
		 * @param {string} key The `type::uuid` key
		 * @return {boolean} True when no new subscribe is needed
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		isLiveScopeCurrent(key) {
			if (this.liveHandle && this.liveKey === key) {
				return true
			}
			// A subscribe for this exact object already in flight: re-subscribing
			// here would leak the first handle + watcher.
			return this.livePendingKey === key
		},

		/**
		 * Take the handle a subscribe resolved with, or drop it when the
		 * subscription was released while awaiting.
		 *
		 * @param {object} scope  The store, type, uuid, key and epoch of the subscribe
		 * @param {object} handle The handle the store returned
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		adoptLiveHandle(scope, handle) {
			if (this.livePendingKey === scope.key) {
				this.livePendingKey = ''
			}
			if (this.liveEpoch !== scope.epoch) {
				// Released while awaiting (another object opened, or the
				// component was destroyed) — drop the stale subscription.
				scope.store.unsubscribe(handle)
				return
			}
			this.liveHandle = handle
			if (typeof this.applyLiveObject === 'function') {
				this.liveUnwatch = this.watchLiveObject(scope)
			}
		},

		/**
		 * Bridge: event → plugin refetch → objects[type][uuid] cache →
		 * component-local state (draft/pristine).
		 *
		 * @param {object} scope The store, type, uuid and key of the subscription
		 * @return {() => void} The unwatch function
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		watchLiveObject(scope) {
			return this.$watch(
				() => scope.store.getObject(scope.type, scope.uuid),
				(fresh) => {
					if (fresh && this.liveKey === scope.key) {
						this.applyLiveObject(fresh)
					}
				},
			)
		},

		/**
		 * Reset the subscription state after a failed subscribe.
		 *
		 * @param {string} key The `type::uuid` key of the failed subscribe
		 * @param {unknown} error What the subscribe rejected with
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		dropFailedLiveSubscription(key, error) {
			if (this.livePendingKey === key) {
				this.livePendingKey = ''
			}
			this.liveHandle = null
			this.liveKey = ''
			console.warn(
				'[liveObjectSubscription] subscribe failed:',
				error?.message ?? error,
			)
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
