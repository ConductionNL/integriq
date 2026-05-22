<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'testSource'"
		ref="modalRef"
		label-id="testSource"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ t('openconnector', 'Test source') }}</h2>

			<form @submit.prevent="handleSubmit">
				<div class="form-group">
					<div class="detailGrid">
						<NcSelect
							id="method"
							v-bind="methodOptions"
							v-model="methodOptions.value" />

						<NcSelect
							id="type"
							v-bind="typeOptions"
							v-model="typeOptions.value" />

						<NcTextField
							id="endpoint"
							:label="t('openconnector', 'Endpoint')"
							:value.sync="testSourceItem.endpoint" />
					</div>
					<NcTextArea
						id="body"
						resize="vertical"
						:label="t('openconnector', 'Body')"
						:value.sync="testSourceItem.body" />
				</div>
			</form>

			<div class="modal-actions">
				<NcButton
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					:disabled="loading"
					type="primary"
					@click="testSource()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Sync v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Test connection') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="sourceStore.sourceTest && sourceStore.sourceTest.response.statusCode.toString().startsWith('2')" type="success">
				<p>{{ t('openconnector', 'The connection to the source was successful.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="(sourceStore.sourceTest && !sourceStore.sourceTest.response.statusCode.toString().startsWith('2')) || error" type="error">
				<p>{{ t('openconnector', 'An error occurred while testing the connection:') }} {{ sourceStore.sourceTest ? sourceStore.sourceTest.response.statusMessage : error }}</p>
			</NcNoteCard>

			<div v-if="sourceStore.sourceTest">
				<p><b>{{ t('openconnector', 'Status') }}:</b> {{ sourceStore.sourceTest.response.statusMessage }} ({{ sourceStore.sourceTest.response.statusCode }})</p>
				<p><b>{{ t('openconnector', 'Response time') }}:</b> {{ sourceStore.sourceTest.response.responseTime }} ({{ t('openconnector', 'Milliseconds') }})</p>
				<p><b>{{ t('openconnector', 'Size') }}:</b> {{ sourceStore.sourceTest.response.size }} ({{ t('openconnector', 'Bytes') }})</p>
				<p><b>{{ t('openconnector', 'Remote IP') }}:</b> {{ sourceStore.sourceTest.response.remoteIp }}</p>
				<p><b>{{ t('openconnector', 'Headers') }}:</b> {{ sourceStore.sourceTest.response.headers }}</p>
				<div class="responseBody">
					<b>{{ t('openconnector', 'Body') }}:</b> <pre>{{ prettifyJson(sourceStore.sourceTest.response.body) }}</pre>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcSelect,
	NcLoadingIcon,
	NcTextField,
	NcTextArea,
	NcNoteCard,
} from '@nextcloud/vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'TestSource',
	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcTextField,
		NcTextArea,
		NcNoteCard,
		CancelIcon,
	},
	data() {
		return {
			testSourceItem: {
				endpoint: '',
				body: '',
				method: '',
				type: '',
			},
			success: false,
			loading: false,
			error: false,
			typeOptions: {
				inputLabel: 'Type',
				options: [
					{ id: 'JSON', label: 'JSON' },
					{ id: 'XML', label: 'XML' },
					{ id: 'YAML', label: 'YAML' },
				],
				value: { id: 'JSON', label: 'JSON' },
			},
			methodOptions: {
				inputLabel: 'Method',
				options: [
					{ id: 'GET', label: 'GET' },
					{ id: 'POST', label: 'POST' },
					{ id: 'PUT', label: 'PUT' },
					{ id: 'DELETE', label: 'DELETE' },
				],
				value: { id: 'GET', label: 'GET' },
			},
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.succes = false
			this.loading = false
			this.error = false
			this.testSourceItem = {
				endpoint: '',
				body: '',
				method: '',
				type: '',
			}
		},
		async testSource() {
			this.loading = true

			try {
				await sourceStore.testSource({
					...this.testSourceItem,
					method: this.methodOptions.value.id,
					type: this.typeOptions.value.id,
				})
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.error = false
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'Er is een fout opgetreden bij het opslaan van de bron'
				sourceStore.setSourceTest(false)
			}
		},
		prettifyJson(json) {
			if (!json) return ''
			try {
				return JSON.stringify(JSON.parse(json), null, 2)
			} catch (error) {
				return json
			}
		},
	},
}
</script>
<style scoped>
.detailGrid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 5px;
}
</style>

<style scoped>
.responseBody {
	text-align: left;
}
</style>
