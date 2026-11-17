<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CallLogDetail: the row-detail body of the Source logs page (manifest
  `slots.row-detail`; outbound-call-log-investigation-window-page-and-allowlist,
  Task 6).

  Shows the record as the built-in row detail does (its plain fields as a
  grid, each nested bag as a grid or as JSON), and says why a record has no
  body: a call made while no investigation window was open on its source
  stores none (`bodyCaptured` is not true).

  @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
-->
<template>
	<div class="call-log-detail" data-testid="call-log-detail">
		<NcNoteCard
			v-if="!captured"
			type="info"
			data-testid="call-log-no-body">
			{{ t('integriq', 'No body stored: no investigation window was open') }}
		</NcNoteCard>
		<CnDetailGrid
			v-if="scalarItems.length > 0"
			layout="horizontal"
			:items="scalarItems" />
		<template v-for="block in objectBlocks" :key="block.key">
			<h4 class="call-log-detail__heading">
				{{ block.label }}
			</h4>
			<CnDetailGrid
				v-if="block.items"
				layout="horizontal"
				:items="block.items" />
			<pre v-else class="call-log-detail__json">{{ block.json }}</pre>
		</template>
	</div>
</template>

<script>
import { CnDetailGrid } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'

/**
 * A field name as a label: `statusCode` reads "Status code".
 *
 * @param {string} key The field name.
 * @return {string}
 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
 */
export function humanise(key) {
	const spaced = String(key).replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').toLowerCase()
	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

export default {
	name: 'CallLogDetail',

	components: { CnDetailGrid, NcNoteCard },

	props: {
		/** The clicked call log record. */
		row: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * Whether the call was made inside an investigation window.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		captured() {
			return this.row.bodyCaptured === true
		},

		/**
		 * The record's filled fields, without the `@self` metadata.
		 *
		 * @return {Array<Array>}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		entries() {
			return Object.entries(this.row)
				.filter(([key, value]) => key !== '@self' && value !== null && value !== undefined && value !== '')
		},

		scalarItems() {
			return this.entries
				.filter(([, value]) => typeof value !== 'object')
				.map(([key, value]) => ({ label: humanise(key), value: String(value) }))
		},

		/**
		 * Each nested bag: a grid when its values are plain, else JSON.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		objectBlocks() {
			return this.entries
				.filter(([, value]) => typeof value === 'object')
				.map(([key, value]) => {
					const inner = Object.entries(value)
					const flat = Array.isArray(value) === false
						&& inner.every(([, v]) => v === null || typeof v !== 'object')
					return {
						key,
						label: humanise(key),
						items: flat ? inner.map(([k, v]) => ({ label: humanise(k), value: String(v) })) : null,
						json: flat ? null : JSON.stringify(value, null, 2),
					}
				})
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.call-log-detail {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.call-log-detail__heading {
	margin: 8px 0 0;
	font-weight: 600;
}

.call-log-detail__json {
	white-space: pre-wrap;
	word-break: break-word;
	padding: 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}
</style>
