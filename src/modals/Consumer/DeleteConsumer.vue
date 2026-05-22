<script setup>
import { translate as t } from '@nextcloud/l10n'
import { consumerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteConsumer'"
		:name="t('openconnector', 'Delete consumer')"
		size="normal"
		:can-close="false">
		<p v-if="!success">
			{{ t('openconnector', 'Do you want to delete') }} <b>{{ consumerStore.getConsumerItem()?.name }}</b>? {{ t('openconnector', 'This action cannot be undone.') }}
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openconnector', 'Successfully deleted consumer') }}</p>
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
				@click="deleteConsumer()">
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
	name: 'DeleteConsumer',
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
		async deleteConsumer() {
			this.loading = true

			await consumerStore.deleteConsumer(consumerStore.consumerItem.id)
				.then(({ response }) => {
					this.success = response.ok
					this.error = false
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				}).catch((e) => {
					this.success = false
					this.error = e.message || 'An error occurred while deleting the consumer'
				}).finally(() => {
					this.loading = false
				})

		},
	},
}
</script>
