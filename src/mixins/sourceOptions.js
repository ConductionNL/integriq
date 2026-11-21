// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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
 * The source picker shared by the endpoint editor's fields: the loaded
 * sources, their loading flag, and the sources as select options. A failed
 * load leaves the list empty.
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
export default {
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
	},
}
