// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Shared draft of the per-type source settings components
 * (CaseSystemSourceFields, CtiSourceFields). The component is handed the
 * source's `configuration`, keeps its own draft, and emits the whole
 * configuration on every change, so several edits in a row never read a stale
 * prop and keys the component does not know are kept.
 *
 * It also loads the instance's sources for the component's source pickers.
 * The component says which sources it offers with `acceptsSource(item)`.
 *
 * @spec openspec/changes/archive/2026-11-24-kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
export default {
	props: {
		/** The source's configuration object. */
		configuration: { type: Object, default: () => ({}) },
	},

	emits: ['update:configuration'],

	data() {
		return {
			draft: { ...(this.configuration ?? {}) },
			sourceOptions: [],
			sourcesLoading: false,
		}
	},

	watch: {
		/**
		 * Take over a configuration the parent swaps in, unless it is the one
		 * this component just emitted.
		 *
		 * @param {object} next the new configuration
		 * @return {void}
		 * @spec exclude reactive-state sync passthrough
		 */
		configuration(next) {
			if (next === this.lastEmitted) return
			this.draft = { ...(next ?? {}) }
		},
	},

	/**
	 * Load the sources the component's pickers offer.
	 *
	 * @return {void}
	 * @spec openspec/changes/archive/2026-11-24-kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	created() {
		this.lastEmitted = null
		this.fetchSources()
	},

	methods: {
		/**
		 * Whether a source is offered in the pickers. Every source, unless the
		 * component says otherwise.
		 *
		 * @return {boolean} true to offer it
		 * @spec exclude default filter, overridden per component
		 */
		acceptsSource() {
			return true
		},

		/**
		 * Load the instance's sources the component accepts. A failed fetch
		 * leaves an empty list, never a broken editor.
		 *
		 * @return {Promise<void>} resolves once the list is loaded
		 * @spec openspec/changes/archive/2026-11-24-kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/integriq/source'),
					// `_limit`, not `limit`: an unprefixed param is a property filter.
					{ params: { _limit: 500 } },
				)
				const list = Array.isArray(response?.data?.results)
					? response.data.results
					: []
				this.sourceOptions = list
					.filter((item) => this.acceptsSource(item))
					.map((item) => ({
						id: String(item['@self']?.id || item.id || item.uuid),
						label: item.name || item.title || String(item.id),
					}))
			} catch {
				this.sourceOptions = []
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * The picker option for a stored source id; an id this instance does
		 * not list is shown as itself rather than as empty.
		 *
		 * @param {string} id the stored source id
		 * @return {object|null} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		optionFor(id) {
			if (!id) return null
			return (
				this.sourceOptions.find((option) => option.id === String(id)) ?? {
					id: String(id),
					label: String(id),
				}
			)
		},

		/**
		 * Write one key and emit the whole configuration.
		 *
		 * @param {string} key the configuration key the backend reads
		 * @param {string|boolean|object} value the value
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		write(key, value) {
			this.draft = { ...this.draft, [key]: value }
			this.emitDraft()
		},

		/**
		 * Emit a copy of the draft and remember it, so the watcher does not
		 * reset the draft when the parent hands it back.
		 *
		 * @return {void}
		 * @spec exclude emit passthrough
		 */
		emitDraft() {
			const configuration = { ...this.draft }
			this.lastEmitted = configuration
			this.$emit('update:configuration', configuration)
		},
	},
}
