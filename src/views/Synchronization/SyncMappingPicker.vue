<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SyncMappingPicker — three NcSelect rows for the mapping slugs a
  Synchronization owns:

    - sourceTargetMapping  — required source→target transformation
    - targetSourceMapping  — optional reverse (bidirectional only)
    - sourceHashMapping    — optional change-detection hash mapping

  Each row is the same Mapping picker, populated once and shared via the
  parent's `mappingOptions`. Reuses the #847 sync-picker pattern
  (NcSelect populated from OR's `/api/objects/openconnector/mapping`).

  Persisted shape: Mapping is referenced by `slug` per the
  `synchronization.sourceTargetMapping` field description in
  `lib/Settings/openconnector_register.json` ("Mapping slug for
  source-to-target field transformation"). We render the human label
  but emit the slug back up.

  #878 follow-up: a collapsible SyncMappingPreview is attached under the
  primary picker so users can verify the picked transformation against a
  sample object without leaving the page (legacy flow was a separate
  "Test mapping" modal accessed from the mapping detail page).
-->

<template>
	<div class="sync-mapping">
		<div class="sync-mapping__field">
			<label :for="primaryId" class="sync-mapping__label">
				{{ t('openconnector', 'Source → Target mapping *') }}
			</label>
			<NcSelect
				:input-id="primaryId"
				:aria-label-combobox="t('openconnector', 'Source → Target mapping')"
				:model-value="selectedPrimary"
				:options="mappingOptions"
				:loading="loading"
				:placeholder="t('openconnector', 'Pick a mapping')"
				@update:model-value="
					(option) => $emit('update:value', option?.id || '')
				" />
			<span class="sync-mapping__helper">
				{{
					t(
						'openconnector',
						'Transforms each source record into the target shape.',
					)
				}}
			</span>
			<SyncMappingPreview :mapping-id="value" />
		</div>

		<div class="sync-mapping__field">
			<label :for="reverseId" class="sync-mapping__label">
				{{ t('openconnector', 'Target → Source mapping') }}
			</label>
			<NcSelect
				:input-id="reverseId"
				:aria-label-combobox="t('openconnector', 'Target → Source mapping')"
				:model-value="selectedReverse"
				:options="mappingOptions"
				:loading="loading"
				:clearable="true"
				:placeholder="
					t('openconnector', 'Optional — for bidirectional sync')
				"
				@update:model-value="
					(option) => $emit('update:targetSourceValue', option?.id || '')
				" />
			<span class="sync-mapping__helper">
				{{
					t(
						'openconnector',
						'Only needed when changes flow back from target to source.',
					)
				}}
			</span>
		</div>

		<div class="sync-mapping__field">
			<label :for="hashId" class="sync-mapping__label">
				{{ t('openconnector', 'Hash mapping') }}
			</label>
			<NcSelect
				:input-id="hashId"
				:aria-label-combobox="t('openconnector', 'Hash mapping')"
				:model-value="selectedHash"
				:options="mappingOptions"
				:loading="loading"
				:clearable="true"
				:placeholder="t('openconnector', 'Optional — change detection')"
				@update:model-value="
					(option) => $emit('update:hashValue', option?.id || '')
				" />
			<span class="sync-mapping__helper">
				{{
					t(
						'openconnector',
						'Mapping used to compute the source-side hash for change detection.',
					)
				}}
			</span>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import SyncMappingPreview from './SyncMappingPreview.vue'

let pickerSeq = 0

export default {
	name: 'SyncMappingPicker',

	components: {
		NcSelect,
		SyncMappingPreview,
	},

	props: {
		/** Required primary mapping slug. */
		value: { type: String, default: '' },
		/** Optional reverse-direction mapping slug. */
		targetSourceValue: { type: String, default: '' },
		/** Optional hash mapping slug. */
		hashValue: { type: String, default: '' },
	},

	data() {
		const seq = ++pickerSeq
		return {
			pickerUid: seq,
			mappingOptions: [],
			loading: false,
		}
	},

	computed: {
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		primaryId() {
			return `sync-mapping-${this.pickerUid}-primary`
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		reverseId() {
			return `sync-mapping-${this.pickerUid}-reverse`
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		hashId() {
			return `sync-mapping-${this.pickerUid}-hash`
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedPrimary() {
			return this.resolveOption(this.value)
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedReverse() {
			return this.resolveOption(this.targetSourceValue)
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedHash() {
			return this.resolveOption(this.hashValue)
		},
	},

	mounted() {
		this.fetchMappings()
	},

	methods: {
		/**
		 * Turn a stored mapping slug into the option object NcSelect renders.
		 * Falls back to a synthetic `{ id, label }` so a slug that is not in
		 * the fetched list (or is still loading) still shows its own value.
		 * @param {string|number} id The stored mapping slug; falsy means "none
		 *   selected".
		 * @return {{id: string, label: string}|null} The matching option, a
		 *   synthetic stand-in, or null when nothing is selected.
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		resolveOption(id) {
			if (!id) return null
			return (
				this.mappingOptions.find((opt) => opt.id === String(id)) ?? {
					id: String(id),
					label: String(id),
				}
			)
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async fetchMappings() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/mapping',
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
				this.mappingOptions = list.map((row) => ({
					// Mappings are referenced by slug in the synchronization
					// record (per the register schema description). Fall back
					// to id when slug is unset (legacy rows).
					id: String(row.slug || row.id || row.uuid),
					label: row.name || row.title || row.slug || row.id,
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[SyncMappingPicker] mapping fetch failed', err)
				this.mappingOptions = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.sync-mapping {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sync-mapping__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sync-mapping__label {
	font-weight: bold;
	font-size: 13px;
}

.sync-mapping__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
