<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="openconnector-app-root">
		<CnAppRoot
			:manifest="manifest"
			:custom-components="customComponents"
			:page-types="pageTypes"
			app-id="openconnector"
			:translate="translateForApp"
			:permissions="permissions" />
		<!--
		  ModalHost lives outside CnAppRoot so a route swap mid-modal
		  cannot unmount the modal from under the user. It listens on the
		  shared modalBus and renders #835 (Test mapping) and #836 (Add
		  endpoint rule) modals when their row-action handlers fire.
		-->
		<ModalHost />
	</div>
</template>

<script>
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './store/store.js'
import ModalHost from './modals/v2/ModalHost.vue'

export default {
	name: 'App',
	components: {
		CnAppRoot,
		ModalHost,
	},

	props: {
		manifest: {
			type: Object,
			required: true,
		},
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		pageTypes: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		permissions() {
			const base = window.OC?.currentUser?.permissions ?? []
			// CnAppNav's permission filter is an array-includes check; Nextcloud
			// does not put the boolean admin flag into the permissions array, so
			// we inject it here for manifest entries gated on permission: "admin".
			const isAdmin = typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
			return isAdmin ? [...base, 'admin'] : base
		},
	},

	async created() {
		// Initialise Pinia stores so the existing custom-component views keep
		// working through the manifest transition. CnAppRoot itself does not
		// depend on them, but the views in registry.js do.
		const settingsStore = useSettingsStore()
		await settingsStore.fetchSettings()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('openconnector', key)
		},
	},
}
</script>
