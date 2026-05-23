<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Generic log-index wrapper around CnIndexPage (Tier-4 manifest-v2 pattern).

 Drives runtime store + column selection from `config.logType`. Manifest
 entries reference this wrapper via `component: "LogIndex"` and pass
 `config: { logType: "source" | "endpoint" | "job" | "synchronization" | "event" }`.

 This is the larpingapp generic-wrapper pattern (a thin shim over a typed
 primitive). Each `logType` plugs in the matching pinia store + a manual
 column-definition list (CnIndexPage's `columns` prop instead of schema mode,
 because openconnector log entries are not registered as OpenRegister
 schemas yet — they come from app-owned tables).

 As of this commit only `logType: "source"` is wired up. The other four
 log routes are tracked in issue #814 as a follow-up.

 @see openspec/changes — Tier-4 manifest-v2 pattern
 @see ConductionNL/openconnector#814 — wrapper rollout to other 4 log types
-->
<template>
	<CnIndexPage
		:title="title"
		:description="description"
		:columns="columns"
		:objects="rows"
		:loading="loading"
		:pagination="paginationProp"
		:selectable="true"
		:row-key="'id'"
		@refresh="refresh"
		@row-click="openDetail"
		@page-changed="onPageChanged"
		@page-size-changed="onPageSizeChanged" />
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { logStore, navigationStore, sourceStore } from '../../store/store.js'

/**
 * Per-logType configuration: store key, fetch action, list selector.
 * Add new entries here as more log routes adopt the wrapper.
 */
const LOG_TYPE_CONFIG = {
	source: {
		titleKey: 'Source logs',
		descriptionKey: 'Monitor and analyze source API call logs',
		fetch: (filters) => sourceStore.refreshSourceLogs(filters),
		list: () => sourceStore.sourceLogs?.results ?? [],
		total: () => sourceStore.sourceLogs?.total ?? 0,
		modalName: 'viewSourceLog',
	},
}

export default {
	name: 'LogIndex',
	components: { CnIndexPage },

	props: {
		/**
		 * Log type discriminator. Must match a key in LOG_TYPE_CONFIG above.
		 * Manifest-driven: pages[].config.logType.
		 */
		logType: {
			type: String,
			required: true,
			validator: (v) => Object.prototype.hasOwnProperty.call(LOG_TYPE_CONFIG, v),
		},
	},

	data() {
		return {
			page: 1,
			limit: 20,
		}
	},

	computed: {
		config() {
			return LOG_TYPE_CONFIG[this.logType]
		},
		title() {
			return t('openconnector', this.config.titleKey)
		},
		description() {
			return t('openconnector', this.config.descriptionKey)
		},
		rows() {
			return this.config.list()
		},
		total() {
			return this.config.total()
		},
		loading() {
			return logStore.loading || false
		},
		paginationProp() {
			return {
				page: this.page,
				limit: this.limit,
				total: this.total,
				pages: Math.max(1, Math.ceil(this.total / this.limit)),
			}
		},
		/**
		 * Manual column definitions. CnIndexPage uses these when no `schema`
		 * is provided (openconnector log records are not OpenRegister-backed).
		 */
		columns() {
			return [
				{
					key: 'statusCode',
					label: t('openconnector', 'Status'),
					sortable: true,
					formatter: (row) => `${row.statusCode || '-'} ${row.statusMessage || ''}`.trim(),
				},
				{
					key: 'request.method',
					label: t('openconnector', 'Method'),
					sortable: false,
					formatter: (row) => row.request?.method || '-',
				},
				{
					key: 'request.url',
					label: t('openconnector', 'Endpoint'),
					sortable: false,
					formatter: (row) => row.request?.url || '-',
				},
				{
					key: 'response.responseTime',
					label: t('openconnector', 'Response Time'),
					sortable: true,
					formatter: (row) => {
						const ms = row.response?.responseTime
						return ms ? `${(ms / 1000).toFixed(3)}s` : '-'
					},
				},
				{
					key: 'created',
					label: t('openconnector', 'Created'),
					sortable: true,
					formatter: (row) => row.created ? new Date(row.created).toLocaleString() : '-',
				},
			]
		},
	},

	mounted() {
		this.refresh()
	},

	methods: {
		refresh() {
			return this.config.fetch(logStore.logFilters || {})
		},
		openDetail(row) {
			logStore.setViewLogItem(row)
			navigationStore.setModal(this.config.modalName)
		},
		onPageChanged(page) {
			this.page = page
			this.refresh()
		},
		onPageSizeChanged(limit) {
			this.limit = limit
			this.page = 1
			this.refresh()
		},
	},
}
</script>
