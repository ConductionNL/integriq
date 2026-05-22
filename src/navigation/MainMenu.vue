<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :active="$route.path === '/'" :name="t('openconnector', 'Dashboard')" @click="handleNavigate('/')">
				<template #icon>
					<Finance :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/sources'"
				:name="t('openconnector', 'Sources')"
				:allow-collapse="true"
				:open="true"
				@click="handleNavigate('/sources')">
				<template #icon>
					<DatabaseArrowLeftOutline :size="20" />
				</template>
				<!-- This is correct according to the documentation, thats why there is a disable comment -->
				<!-- eslint-disable-next-line vue/no-lone-template -->
				<template>
					<NcAppNavigationItem :active="$route.path === '/sources/logs'" :name="t('openconnector', 'Logs')" @click="handleNavigate('/sources/logs')">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.matched[0].path === '/endpoints' || $route.matched[0].path === '/endpoints/:id'"
				:name="t('openconnector', 'Endpoints')"
				:allow-collapse="true"
				:open="true"
				@click="handleNavigate('/endpoints')">
				<template #icon>
					<Api :size="20" />
				</template>
				<!-- This is correct according to the documentation, thats why there is a disable comment -->
				<!-- eslint-disable-next-line vue/no-lone-template -->
				<template>
					<NcAppNavigationItem :active="$route.matched[0].path === '/endpoints/logs'" :name="t('openconnector', 'Logs')" @click="handleNavigate('/endpoints/logs')">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/consumers')" :name="t('openconnector', 'Consumers')" @click="handleNavigate('/consumers')">
				<template #icon>
					<Webhook :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/mappings')" :name="t('openconnector', 'Mappings')" @click="handleNavigate('/mappings')">
				<template #icon>
					<SitemapOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/jobs'"
				:name="t('openconnector', 'Jobs')"
				:allow-collapse="true"
				:open="true"
				@click="handleNavigate('/jobs')">
				<template #icon>
					<Update :size="20" />
				</template>
				<!-- This is correct according to the documentation, thats why there is a disable comment -->
				<!-- eslint-disable-next-line vue/no-lone-template -->
				<template>
					<NcAppNavigationItem :active="$route.path === '/jobs/logs'" :name="t('openconnector', 'Logs')" @click="handleNavigate('/jobs/logs')">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('openconnector', 'Cloud Events')"
				:allow-collapse="true"
				:open="true">
				<template #icon>
					<CloudUploadOutline :size="20" />
				</template>
				<!-- This is correct according to the documentation, thats why there is a disable comment -->
				<!-- eslint-disable-next-line vue/no-lone-template -->
				<template>
					<NcAppNavigationItem :active="$route.path.startsWith('/cloud-events/events')" :name="t('openconnector', 'Events')" @click="handleNavigate('/cloud-events/events')">
						<template #icon>
							<MessageTextFastOutline :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :active="$route.path === '/cloud-events/logs'" :name="t('openconnector', 'Logs')" @click="handleNavigate('/cloud-events/logs')">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/synchronizations'"
				:name="t('openconnector', 'Synchronization')"
				:allow-collapse="true"
				:open="true"
				@click="handleNavigate('/synchronizations')">
				<template #icon>
					<VectorPolylinePlus :size="20" />
				</template>
				<!-- This is correct according to the documentation, thats why there is a disable comment -->
				<!-- eslint-disable-next-line vue/no-lone-template -->
				<template>
					<NcAppNavigationItem :active="$route.path === '/synchronizations/contracts'" :name="t('openconnector', 'Contracts')" @click="handleNavigate('/synchronizations/contracts')">
						<template #icon>
							<FileDocumentOutline :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :active="$route.path === '/synchronizations/logs'" :name="t('openconnector', 'Logs')" @click="handleNavigate('/synchronizations/logs')">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/rules')" :name="t('openconnector', 'Rules')" @click="handleNavigate('/rules')">
				<template #icon>
					<SitemapOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
		<NcAppNavigationSettings>
			<NcAppNavigationItem :active="$route.path === '/import'" :name="t('openconnector', 'Import')" @click="handleNavigate('/import')">
				<template #icon>
					<FileImportOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('openconnector', 'Settings')" @click="$emit('open-settings')">
				<template #icon>
					<Cog :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationSettings>
	</NcAppNavigation>
</template>

<script>
import {
	NcAppNavigation,
	NcAppNavigationList,
	NcAppNavigationItem,
	NcAppNavigationSettings,
} from '@nextcloud/vue'

// Icons
import Finance from 'vue-material-design-icons/Finance.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import Api from 'vue-material-design-icons/Api.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Update from 'vue-material-design-icons/Update.vue'
import VectorPolylinePlus from 'vue-material-design-icons/VectorPolylinePlus.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import MessageTextFastOutline from 'vue-material-design-icons/MessageTextFastOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		// icons
		Finance,
		DatabaseArrowLeftOutline,
		Api,
		Webhook,
		SitemapOutline,
		Update,
		VectorPolylinePlus,
		CloudUploadOutline,
		MessageTextFastOutline,
		FileImportOutline,
		FileDocumentOutline,
		TextBoxOutline,
		Cog,
	},
	methods: {
		handleNavigate(path) {
			this.$router.push(path)
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
