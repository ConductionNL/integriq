<script setup>
import { logStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewSynchronizationContract'"
		ref="modalRef"
		label-id="viewSynchronizationContract"
		@close="closeModal">
		<div class="logModalContent">
			<div class="logModalContentHeader">
				<h2>View Synchronization Contract</h2>
			</div>

			<strong>Standard</strong>
			<table class="table">
				<tr v-for="(value, key) in standardItems" :key="key">
					<td class="keyColumn">
						<b>{{ key }}</b>
					</td>

					<td v-if="typeof value === 'string' && getValidISOstring(value)">
						{{ new Date(value).toLocaleString() }}
					</td>
					<td v-else>
						{{ value || '-' }}
					</td>
				</tr>
			</table>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
} from '@nextcloud/vue'

import getValidISOstring from '../../services/getValidISOstring.js'

export default {
	name: 'ViewSynchronizationContract',
	components: {
		NcModal,
	},
	data() {
		return {
			hasUpdated: false,
			standardItems: {},
			sourceItems: {},
			targetItems: {},
		}
	},
	mounted() {
		logStore.viewLogItem && this.splitItems()
	},
	updated() {
		if (navigationStore.modal === 'viewSynchronizationContract' && !this.hasUpdated) {
			logStore.viewLogItem && this.splitItems()
			this.hasUpdated = true
		}
	},
	methods: {
		splitItems() {
			Object.entries(logStore.viewLogItem).forEach(([key, value]) => {
				if (key === 'target' || key === 'source') {
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
			this.sourceItems = {}
			this.targetItems = {}
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

</style>

<style scoped>
.table {
	border: 1px solid grey; /* Add a grey border around the table */
	border-collapse: collapse; /* Ensure borders are collapsed for a cleaner look */
	width: 100%; /* Optional: make the table take full width */
}

.table td, .table th {
	border: 1px solid grey; /* Add a grey border around each cell */
	padding: 8px; /* Add padding to table cells */
}
</style>
