<script setup>
import { translate as t } from '@nextcloud/l10n'
import { consumerStore, navigationStore } from '../../store/store.js'
import { Consumer } from '../../entities/index.js'
import { getTheme } from '../../services/getTheme.js'
</script>

<template>
	<NcModal
		v-if="navigationStore.modal === 'editConsumer'"
		ref="modalRef"
		label-id="editConsumer"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ consumerItem.id ? t('openconnector', 'Edit Consumer') : t('openconnector', 'Add Consumer') }}</h2>

			<div v-if="success !== null">
				<NcNoteCard v-if="success" type="success">
					<p>{{ consumerItem.id ? t('openconnector', 'Consumer successfully updated') : t('openconnector', 'Consumer successfully added') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>

			<form v-if="success === null">
				<div class="form-group editConsumerForm">
					<NcTextField :label="t('openconnector', 'Name') + '*'" :value.sync="consumerItem.name" />

					<NcTextArea
						resize="vertical"
						:label="t('openconnector', 'Description')"
						:value.sync="consumerItem.description" />

					<NcTextArea
						resize="vertical"
						:label="t('openconnector', 'Domains')"
						:value.sync="consumerItem.domains"
						:helper-text="t('openconnector', 'Enter domains separated by commas (e.g. example.com, example.org).')" />

					<NcTextArea
						:label="t('openconnector', 'IPs')"
						:value.sync="consumerItem.ips"
						:helper-text="t('openconnector', 'Enter IP\'s separated by commas (e.g. 127.0.0.1, 1.1.1.1).')"
						resize="none" />

					<NcSelect
						v-bind="authorizationTypeOptions"
						v-model="authorizationTypeOptions.value" />
					<div :class="`codeMirrorContainer ${getTheme()}`">
						<span>{{ t('openconnector', 'Authorization Configuration') }}</span>
						<CodeMirror
							v-model="authConfig"
							:basic="true"
							:dark="getTheme() === 'dark'"
							:lang="json()"
							:linter="jsonParseLinter()"
							:placeholder="t('openconnector', 'Enter authorization configuration in JSON format...')" />
					</div>
					<NcButton class="prettifyButton" @click="prettifyJson">
						<template #icon>
							<AutoFix :size="20" />
						</template>
						{{ t('openconnector', 'Prettify') }}
					</NcButton>
				</div>
			</form>
			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="success === null"
					:disabled="loading || !consumerItem.name || !isAuthConfigValid"
					type="primary"
					@click="editConsumer()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-else :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>
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
	NcNoteCard,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditConsumer',
	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcTextArea,
		CodeMirror,
		ContentSaveOutline,
		AutoFix,
		CancelIcon,
	},
	data() {
		return {
			consumerItem: {
				id: null,
				name: '',
				description: '',
				domains: '',
				ips: '',
				authorizationType: '',
				authorizationConfiguration: [],
			},
			authConfig: '[]',
			success: null,
			loading: false,
			error: false,
			authorizationTypeOptions: {
				inputLabel: 'Authorization Type',
				options: [
					{ label: 'none' },
					{ label: 'basic' },
					{ label: 'bearer' },
					{ label: 'apiKey' },
					{ label: 'oauth2' },
					{ label: 'jwt' },
				],
				value: { label: 'none' },
			},
			hasUpdated: false,
			closeTimeoutFunc: null,
		}
	},
	computed: {
		isAuthConfigValid() {
			try {
				JSON.parse(this.authConfig)
				return true
			} catch (e) {
				return false
			}
		},
	},
	mounted() {
		this.initializeConsumerItem()
	},
	updated() {
		if (navigationStore.modal === 'editConsumer' && !this.hasUpdated) {
			this.initializeConsumerItem()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeConsumerItem() {
			const item = consumerStore.getConsumerItem()
			if (item?.id) {
				this.consumerItem = {
					...item,
					domains: Array.isArray(item.domains)
						? item.domains.join(', ')
						: item.domains,
					ips: Array.isArray(item.ips)
						? item.ips.join(', ')
						: item.ips,
				}
				if (this.authorizationTypeOptions.options
					.map(i => i.label)
					.includes(item.authorizationType)) {
					this.authorizationTypeOptions.value = { label: item.authorizationType }
				}
				this.authConfig = JSON.stringify(item.authorizationConfiguration || [], null, 2)
			}
		},

		prettifyJson() {
			try {
				this.authConfig = JSON.stringify(JSON.parse(this.authConfig), null, 2)
			} catch (e) {
				this.error = 'Invalid JSON in authorization configuration'
			}
		},

		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.consumerItem = {
				id: null,
				name: '',
				description: '',
				domains: '',
				ips: '',
				authorizationType: '',
				authorizationConfiguration: [],
			}
			this.authorizationTypeOptions.value = { label: 'none' }
			this.authConfig = '[]'
		},

		async editConsumer() {
			this.loading = true
			const parsedAuthConfig = JSON.parse(this.authConfig)
			const finalAuthorizationConfiguration
			= (Array.isArray(parsedAuthConfig) && parsedAuthConfig.length === 0) || (parsedAuthConfig && typeof parsedAuthConfig === 'object' && !Array.isArray(parsedAuthConfig) && Object.keys(parsedAuthConfig).length === 0)
			 ? null
				: parsedAuthConfig

			const updatedConsumer = {
				...this.consumerItem,
				domains: this.consumerItem.domains.trim().split(/ *, */g).filter(Boolean),
				ips: this.consumerItem.ips.trim().split(/ *, */g).filter(Boolean),
				authorizationType: this.authorizationTypeOptions.value.label,
				authorizationConfiguration: finalAuthorizationConfiguration,
			}
			const newConsumer = new Consumer(updatedConsumer)
			consumerStore.saveConsumer(newConsumer)
				.then(({ response }) => {
					this.success = response.ok
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch((e) => {
					this.success = false
					this.error = e.message || 'An error occurred while saving the consumer'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style lang="css" scoped>
.editConsumerForm .textarea__helper-text-message {
	padding-block: 4px;
	padding-inline: var(--border-radius-large);
	display: flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
}
</style>

<style scoped>
.codeMirrorContainer {
	margin-block-start: 20px;
	text-align: left;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}

.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}

.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}

.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
}

/* selection color */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
	color: black;
}

.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
	color: black;
}

/* string */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
	color: #2d770f;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
	color: #104e0c;
}

/* boolean */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
	color: #221199;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
	color: #4026af;
}

/* null */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

/* number */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
	color: #8c5c2c;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
	color: #623907;
}
</style>
