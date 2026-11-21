// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { CnFieldHelper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * A source as a select option: its uuid and its name.
 *
 * @param {object} source One source object.
 * @return {{id: string, label: string}} The option.
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export function sourceOption(source) {
	const id = source.id || source.uuid
	return { id, label: source.name || id }
}

/**
 * What the endpoint editor's per-target-type fields (McpEndpointFields,
 * EndpointTargetsFields) share: the controls they draw, the form data and
 * mutator EndpointFormFields hands them, and the sources they pick from,
 * loaded when the fields are created. A failed load leaves the list empty.
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export default {
	components: { CnFieldHelper, NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },

	props: {
		/** Reactive form data object owned by CnFormDialog. */
		formData: { type: Object, default: () => ({}) },
		/** Mutator forwarded from CnFormDialog. Signature: (key, value). */
		updateField: { type: Function, required: true },
	},

	data() {
		return {
			sources: [],
			sourcesLoading: false,
		}
	},

	computed: {
		/**
		 * The sources to pick from.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
		 */
		sourceOptions() {
			return this.sources.map(sourceOption)
		},
	},

	async created() {
		await this.fetchSources()
	},

	methods: {
		/**
		 * Load the sources.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/integriq/source'), { params: { _limit: 200 } })
				this.sources = Array.isArray(data?.results) ? data.results : []
			} catch {
				this.sources = []
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * The option of a source uuid, null when it is not among the sources.
		 *
		 * @param {string} id The source uuid.
		 * @return {{id: string, label: string}|null}
		 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
		 */
		optionFor(id) {
			return this.sourceOptions.find((option) => option.id === id) || null
		},
	},
}
