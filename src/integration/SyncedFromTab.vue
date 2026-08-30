<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SyncedFromTab — Integriq's bespoke "Synced from" leaf component.

  Registered on the OpenRegister integration registry (Path 2) under id
  `sync-contract`, used as BOTH the sidebar `tab` and the detail-page
  `widget`. It renders the provenance chain for any OR object: which
  synchronization(s) wrote it, when they last ran, and a deep-link into
  the Integriq synchronization editor.

  The registry mounts this with the shared object context props
  ({ objectId, register, schema, apiBase }); the detail/dashboard
  surfaces additionally pass `surface`. We fetch the same sub-resource
  endpoint the generic CnIntegrationCard would
  (`/api/objects/{register}/{schema}/{id}/integrations/sync-contract`),
  but render a richer source → sync → last-run layout.

  Per AD-23 a 5xx / unavailable endpoint renders a quiet "currently
  unavailable" banner rather than a broken tab.
-->
<template>
	<div class="oc-synced-from" data-testid="oc-synced-from">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="unavailable" class="oc-synced-from__notice">
			{{ t('integriq', 'Sync information is currently unavailable.') }}
		</div>

		<div v-else-if="rows.length === 0" class="oc-synced-from__empty">
			{{ t('integriq', 'This object was not created by a synchronization.') }}
		</div>

		<ul v-else class="oc-synced-from__list">
			<li v-for="row in rows" :key="row.id" class="oc-synced-from__row">
				<div class="oc-synced-from__row-icon">
					<SyncIcon :size="20" />
				</div>
				<div class="oc-synced-from__row-body">
					<a v-if="row.url" :href="row.url" class="oc-synced-from__title">
						{{ row.title || t('integriq', 'Synchronization') }}
					</a>
					<span v-else class="oc-synced-from__title">
						{{ row.title || t('integriq', 'Synchronization') }}
					</span>
					<span v-if="row.subtitle" class="oc-synced-from__subtitle">{{
						row.subtitle
					}}</span>
					<span v-if="row.originId" class="oc-synced-from__origin">
						{{ t('integriq', 'Origin id:') }}
						<code>{{ row.originId }}</code>
					</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import SyncIcon from 'vue-material-design-icons/Sync.vue'

export default {
	name: 'SyncedFromTab',

	components: {
		NcLoadingIcon,
		SyncIcon,
	},

	props: {
		/** OR object uuid the surface is rendering. */
		objectId: { type: String, default: '' },
		/** OR register slug. */
		register: { type: String, default: '' },
		/** OR schema slug. */
		schema: { type: String, default: '' },
		/**
		 * API base injected by the registry surface. Falls back to the
		 * OpenRegister app path so the component also works when mounted
		 * standalone (e.g. tests / storybook).
		 */
		apiBase: { type: String, default: '' },
		/**
		 * Render surface — 'detail-page' | 'app-dashboard' |
		 * 'single-entity' | 'user-dashboard'. Unused for now (the layout
		 * is identical across surfaces) but accepted so the registry's
		 * v-bind doesn't warn.
		 */
		surface: { type: String, default: 'detail-page' },
	},

	data() {
		return {
			rows: [],
			loading: false,
			unavailable: false,
		}
	},

	computed: {
		/**
		 * Resolve the sub-resource endpoint. Uses the injected apiBase
		 * when present, else the OpenRegister API path.
		 *
		 * @return {string} The integration list endpoint.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-integration-synced-from/tasks.md#task-1
		 */
		endpoint() {
			const base = this.apiBase || generateUrl('/apps/openregister/api')
			return `${base}/objects/${this.register}/${this.schema}/${this.objectId}/integrations/sync-contract`
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler(id) {
				if (id) {
					this.fetchRows()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Fetch the provenance rows for the current object. Sets the
		 * AD-23 `unavailable` flag on 5xx/network error rather than
		 * throwing — the surface degrades quietly.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-integration-synced-from/tasks.md#task-2
		 */
		async fetchRows() {
			this.loading = true
			this.unavailable = false
			try {
				const res = await axios.get(this.endpoint)
				const data = res.data
				this.rows = Array.isArray(data)
					? data
					: data.results || data.rows || []
			} catch (e) {
				this.unavailable = true
				this.rows = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.oc-synced-from {
	padding: 8px 0;
}

.oc-synced-from__notice,
.oc-synced-from__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	padding: 8px 12px;
}

.oc-synced-from__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.oc-synced-from__row {
	display: flex;
	gap: 10px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
}

.oc-synced-from__row:last-child {
	border-bottom: none;
}

.oc-synced-from__row-icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.oc-synced-from__row-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.oc-synced-from__title {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
	text-decoration: none;
}

.oc-synced-from__title[href]:hover {
	text-decoration: underline;
	color: var(--color-primary-element);
}

.oc-synced-from__subtitle {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.oc-synced-from__origin {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.oc-synced-from__origin code {
	font-size: 11px;
}
</style>
