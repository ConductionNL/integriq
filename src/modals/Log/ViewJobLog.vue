<script setup>
import { translate as t } from '@nextcloud/l10n'
import { logStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewJobLog'"
		ref="modalRef"
		label-id="viewJobLog"
		@close="closeModal">
		<div class="logModalContent ViewJobLog">
			<div class="logModalContentHeader">
				<h2>{{ t('openconnector', 'View Job Log') }}</h2>
			</div>

			<div class="dataTable">
				<strong class="tableTitle">{{ t('openconnector', 'Standard') }}</strong>
				<table>
					<tr v-for="(value, key) in standardItems"

						:key="key">
						<td class="keyColumn">
							{{ key }}
						</td>
						<td v-if="typeof value === 'string' && (key === 'created' || key === 'updated' || key === 'expires' || key === 'lastRun' || key === 'nextRun')">
							{{ new Date(value).toLocaleString() }}
						</td>
						<td v-else>
							{{ value }}
						</td>
					</tr>
				</table>
			</div>

			<div class="dataTable">
				<strong class="tableTitle">{{ t('openconnector', 'Arguments') }}</strong>
				<table>
					<tr v-for="(value, key) in argumentsItems"
						:key="key">
						<td class="keyColumn">
							{{ key }}
						</td>
						<td>{{ value }}</td>
					</tr>
				</table>
			</div>

			<div class="dataTable">
				<strong class="tableTitle">{{ t('openconnector', 'Stack Trace') }}</strong>
				<table>
					<tr v-for="(value, key) in stackTraceItems"
						:key="key">
						<td class="keyColumn">
							{{ key }}
						</td>
						<td>{{ value }}</td>
					</tr>
				</table>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
} from '@nextcloud/vue'

export default {
	name: 'ViewJobLog',
	components: {
		NcModal,
	},
	data() {
		return {
			hasUpdated: false,
			standardItems: {},
			stackTraceItems: {},
			argumentsItems: {},
		}
	},
	mounted() {
		logStore.viewLogItem && this.splitItems()
	},
	updated() {
		if (navigationStore.modal === 'viewJobLog' && !this.hasUpdated) {
			logStore.viewLogItem && this.splitItems()
			this.hasUpdated = true
		}
	},
	methods: {
		splitItems() {
			Object.entries(logStore.viewLogItem).forEach(([key, value]) => {
				if (key === 'stackTrace' || key === 'arguments') {
					this[`${key}Items`] = { ...value }
				} else {
					this.standardItems = { ...this.standardItems, [key]: value }
				}
			})
		},
		closeModal() {
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.standardItems = {}
			this.stackTraceItems = {}
			this.argumentsItems = {}
		},
	},

}
</script>
<style scoped>

.responseHeadersTable {
	margin-inline-start: 65px;
}

.responseBody {
	word-break: break-all;
}

.keyColumn {
	padding-inline-end: 10px;
}

/* modal */
div[class='modal-container']:has(.ViewJobLog) {
	width: clamp(150px, 100%, 800px) !important;
}

</style>

<style scoped>
.dataTable {
	display: flex;
	flex-direction: column;
	gap: 10px;
	max-width: 100%;
	overflow: hidden;
}

.dataTable table {
	table-layout: fixed;
	width: 100%;
}

.tableTitle {
	margin-block-end: 10px;
}

.dataTable td {
	white-space: normal !important;
	overflow-wrap: break-word;
	word-break: break-word;
}

.dataTable td:not(.keyColumn) {
	width: calc(100% - 200px); /* Remaining width after keyColumn */
}

.keyColumn {
	width: 200px; /* Fixed width for first column */
	padding-inline-end: 10px;
	font-weight: bold;
	color: var(--color-text-lighter);
}

table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 1rem;
	border-radius: var(--border-radius);
}

td {
	padding: 0.75rem;
	border-bottom: 1px solid var(--color-border);
	white-space: inherit;
	word-wrap: break-word;
	word-break: break-word;
}

tr {
	background-color: var(--color-background) !important;
}

tr:nth-child(odd) td {
	background-color: var(--color-background-hover);
}

tr:last-child td {
	border-bottom: none;
}
</style>
