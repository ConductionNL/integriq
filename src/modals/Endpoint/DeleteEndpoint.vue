<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteEndpoint'"
		:name="t('openconnector', 'Delete endpoint')"
		size="normal"
		:can-close="false">
		<p v-if="!success">
			{{ t('openconnector', 'Do you want to delete') }} <b>{{ endpointStore.endpointItem?.name }}</b>? {{ t('openconnector', 'This action cannot be undone.') }}
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openconnector', 'Successfully deleted endpoint') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton
				@click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('openconnector', 'Close') : t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!success"
				:disabled="loading"
				type="error"
				@click="deleteEndpoint()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openconnector', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteEndpoint',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			closeTimeoutFunc: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
		},
		async deleteEndpoint() {
			this.loading = true

			await endpointStore.deleteEndpoint(endpointStore.endpointItem.id)
				.then(({ response }) => {
					this.success = response.ok
					this.error = false
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				}).catch((e) => {
					this.success = false
					this.error = e.message || 'An error occurred while deleting the endpoint'
				}).finally(() => {
					this.loading = false
				})

		},
	},
}
</script>
