<script setup>
import { logStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewSourceLog'"
		ref="modalRef"
		label-id="viewSourceLog"
		@close="closeModal">
		<div class="logModalContent ViewSourceLog">
			<div class="logModalContentHeader">
				<h2>View Source Log</h2>
			</div>
			<div class="dataTable">
				<strong class="tableTitle">Standard</strong>
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
				<strong class="tableTitle">Request</strong>
				<table>
					<tr v-for="(value, key) in requestItems"
						:key="key">
						<td class="keyColumn">
							{{ key }}
						</td>
						<td>{{ value }}</td>
					</tr>
				</table>
			</div>
			<div class="dataTable">
				<strong class="tableTitle">Response</strong>
				<table>
					<tr v-for="(value, key) in responseItems"
						:key="key">
						<td v-if="key !== 'body' && key !== 'headers'" class="keyColumn">
							{{ key }}
						</td>
						<td v-if="key !== 'body' && key !== 'headers'">
							{{ value }}
						</td>
					</tr>
				</table>
			</div>
			<div class="dataTable">
				<strong class="tableTitle">Headers</strong>
				<table>
					<tr v-for="(value, key) in headersItems"
						:key="key">
						<td class="keyColumn">
							{{ key }}
						</td>
						<td v-if="typeof value === 'object'">
							<ul>
								<li v-for="(subValue, subKey) in value" :key="subKey" :style="value.length > 1 ? 'list-style-type: disc;' : 'list-style-type: none;'">
									{{ isNaN(subKey) ? `${subKey}: ` : '' }}{{ subValue }}
								</li>
							</ul>
						</td>
						<td v-else>
							{{ value }}
						</td>
					</tr>
				</table>
			</div>
			<div class="responseBody">
				<strong class="responseBodyLabel">Body</strong>
				<div class="responseBodyContent">
					<div v-if="!responseItems.body || responseItems.body === 'Not Found'" class="notFoundText">
						{{ responseItems.body }}
					</div>
					<div v-else-if="isValidJson(responseItems.body)" class="responseBodyJson">
						<NcActions class="responseBodyJsonActions">
							<NcActionButton @click="copyToClipboard(JSON.stringify(JSON.parse(responseItems.body), null, 2))">
								<template #icon>
									<ContentCopy :size="20" />
								</template>
								Copy to clipboard
							</NcActionButton>
						</NcActions>
						<div class="responseBody">
							<span class="responseBodyLabel">body</span>
							<div class="responseBodyContent">
								<div v-if="isValidJson(responseItems.body)" class="responseBodyJson">
									<NcActions class="responseBodyJsonActions">
										<NcActionButton close-after-click @click="copyToClipboard(JSON.stringify(JSON.parse(responseItems.body), null, 2))">
											<template #icon>
												<ContentCopy :size="20" />
											</template>
											Copy to clipboard
										</NcActionButton>
									</NcActions>

									{{ JSON.stringify(JSON.parse(responseItems.body), null, 2) }}
								</div>
								<div v-else>
									{{ responseItems.body }}
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcActionButton,
	NcActions,
} from '@nextcloud/vue'

import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

import isValidJson from '../../services/isValidJson.js'

export default {
	name: 'ViewSourceLog',
	components: {
		NcModal,
		NcActionButton,
		NcActions,
		ContentCopy,
	},
	data() {
		return {
			hasUpdated: false,
			standardItems: {},
			requestItems: {},
			responseItems: {},
			headersItems: {},
		}
	},
	mounted() {
		logStore.viewLogItem && this.splitItems()
	},
	updated() {
		if (navigationStore.modal === 'viewSourceLog' && !this.hasUpdated) {
			logStore.viewLogItem && this.splitItems()
			this.hasUpdated = true
		}
	},
	methods: {
		splitItems() {
			Object.entries(logStore.viewLogItem).forEach(([key, value]) => {
				if (key === 'request' || key === 'response') {
					this[`${key}Items`] = { ...value }
				} else {
					this.standardItems = { ...this.standardItems, [key]: value }
				}
			})
			this.headersToObject()
		},
		headersToObject() {
			if (this.responseItems.headers) {
				this.headersItems = { ...this.responseItems.headers }
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.standardItems = {}
			this.requestItems = {}
			this.responseItems = {}
			this.headersItems = {}
		},
		copyToClipboard(text) {
			navigator.clipboard.writeText(text)
		},
	},
}
</script>

<style scoped>
.responseBody {
	word-break: break-all;
	margin-top: 1rem;
	padding: 1rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.keyColumn {
	width: 200px; /* Fixed width for first column */
	padding-inline-end: 10px;
	font-weight: bold;
	color: var(--color-text-lighter);
}

/* modal */
div[class='modal-container']:has(.ViewSourceLog) {
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

.responseBodyJson {
	position: relative;
	font-family: monospace;
	white-space: pre-wrap;
}

.responseBodyJsonActions {
	position: absolute;
	top: 0;
	right: 0;
	transform: translateY(-50%);
	z-index: 1;
}

.notFoundText {
	display: flex;
	justify-content: center;
	font-weight: 600;
	font-size: 24px;
	color:crimson;
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

.responseBodyContent {
	max-height: 400px;
	overflow-y: auto;
	padding: 1rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.responseBodyLabel {
	font-weight: bold;
	color: var(--color-text-lighter);
	display: block;
	margin-bottom: 0.5rem;
}
</style>
