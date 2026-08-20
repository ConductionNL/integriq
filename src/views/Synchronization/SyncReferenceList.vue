<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SyncReferenceList — multi-select picker that edits an array of
  slug/id references on the parent Synchronization. Used for both
  `actions[]` (rule references) and `followUps[]` (other Synchronization
  references) — they have the same shape per the register schema:
  array of slug strings.

  Reuses the #847 picker pattern: NcSelect populated from OR's
  `/api/objects/openregister/api/objects/openconnector/{schema}` endpoint.
  Multi-mode emits an array of slug strings (matching what
  `SynchronizationAction::run()` expects when looking these up).

  The legacy modal stored these as id arrays. Per the register schema
  description ("Rule slugs", "Synchronization slugs") we standardise on
  the slug as the canonical reference; if no slug is set we fall back to
  the id so older rows still resolve.
-->

<template>
	<div class="sync-ref-list">
		<NcSelect
			:inputId="inputId"
			:inputLabel="inputLabel"
			:modelValue="selectedOptions"
			:options="options"
			:loading="loading"
			multiple
			closeableChips
			:placeholder="placeholder"
			@update:modelValue="onChange" />

		<p v-if="!selectedOptions.length" class="sync-ref-list__empty">
			{{ emptyLabel }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'

let listSeq = 0

export default {
	name: 'SyncReferenceList',

	components: {
		NcSelect,
	},

	props: {
		/**
		 * The OR schema slug to fetch options from
		 * (e.g. `rule`, `synchronization`).
		 */
		schema: { type: String, required: true },
		/** Property of each fetched record used as the human label. */
		labelKey: { type: String, default: 'name' },
		/** Current array of slug strings stored on the parent. */
		value: { type: Array, default: () => [] },
		/**
		 * Optional id to exclude from the option list — used by the
		 * followUps picker to keep the current Synchronization out of its
		 * own follow-ups (avoids infinite recursion).
		 */
		excludeId: { type: String, default: '' },
		placeholder: { type: String, default: '' },
		emptyLabel: { type: String, default: '' },
		/** Accessible label for the combobox input. */
		inputLabel: {
			type: String,
			default: () => t('openconnector', 'References'),
		},
	},

	data() {
		const seq = ++listSeq
		return {
			listUid: seq,
			options: [],
			loading: false,
		}
	},

	computed: {
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		inputId() {
			return `sync-ref-list-${this.listUid}`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedOptions() {
			if (!Array.isArray(this.value)) return []
			return this.value.map(
				(id) =>
					this.options.find((opt) => opt.id === String(id)) ?? {
						id: String(id),
						label: String(id),
					},
			)
		},
	},

	watch: {
		schema: {
			immediate: true,
			/** @spec openspec/specs/sync-editor-ui/spec.md */
			handler() {
				this.fetchOptions()
			},
		},
	},

	methods: {
		/**
		 * Flatten the multi-select's option objects back to the array of slug
		 * strings the parent binds, dropping entries without an id.
		 *
		 * @param {Array<{id: string, label: string}>|null} picked The options
		 *   currently selected in the NcSelect; a non-array means "none".
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onChange(picked) {
			const list = Array.isArray(picked) ? picked : []
			this.$emit(
				'input',
				list
					.map((option) => option?.id)
					.filter(Boolean)
					.map(String),
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async fetchOptions() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/'
							+ this.schema,
					),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.options = list
					.map((row) => ({
						// Standardise on slug as the canonical reference
						// (matches the register schema's `actions[]` /
						// `followUps[]` description). Fall back to id/uuid
						// for legacy rows without a slug.
						id: String(row.slug || row.id || row.uuid),
						label:
							row[this.labelKey]
							|| row.title
							|| row.name
							|| row.slug
							|| row.id,
					}))
					.filter(
						(opt) =>
							!this.excludeId || opt.id !== String(this.excludeId),
					)
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn(`[SyncReferenceList] ${this.schema} fetch failed`, err)
				this.options = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.sync-ref-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sync-ref-list__empty {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
