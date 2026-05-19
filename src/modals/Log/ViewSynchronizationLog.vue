<script setup>
import { logStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewSynchronizationLog'"
		ref="modalRef"
		label-id="viewSynchronizationLog"
		@close="closeModal">
		<div class="logModalContent">
			<div class="logModalContentHeader">
				<h2>View Synchronization Log</h2>
			</div>

			<strong>Standard</strong>
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
			<br>

			<strong>Source</strong>
			<table>
				<tr v-for="(value, key) in sourceItems"
					:key="key">
					<td class="keyColumn">
						{{ key }}
					</td>
					<td>{{ value }}</td>
				</tr>
			</table>
			<br>

			<strong>Target</strong>
			<table>
				<tr v-for="(value, key) in targetItems"
					:key="key">
					<td class="keyColumn">
						{{ key }}
					</td>
					<td>{{ value }}</td>
				</tr>
			</table>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
} from '@nextcloud/vue'

export default {
	name: 'ViewSynchronizationLog',
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
		if (navigationStore.modal === 'viewSynchronizationLog' && !this.hasUpdated) {
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
