<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="openconnector-app-root">
		<CnAppRoot
			:aiCompanion="true"
			:manifest="manifest"
			:customComponents="customComponents"
			:registry="registry"
			:pageTypes="pageTypes"
			appId="openconnector"
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
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
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

		/**
		 * V2 component registry (ADR-036) — map of registry-key →
		 * `{ kind, component }`. Forwarded verbatim to CnAppRoot, which resolves
		 * `type:"custom"` page components against the `kind:'page'` entries.
		 * Replaces the deprecated `customComponents` string map for v2 manifests.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},

		pageTypes: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md */
		permissions() {
			const base = window.OC?.currentUser?.permissions ?? []
			// CnAppNav's permission filter is an array-includes check; Nextcloud
			// does not put the boolean admin flag into the permissions array, so
			// we inject it here for manifest entries gated on permission: "admin".
			const isAdmin =
				typeof window.OC?.isUserAdmin === 'function'
					? window.OC.isUserAdmin()
					: false
			return isAdmin ? [...base, 'admin'] : base
		},
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md
		 */
		translateForApp(key) {
			return ncT('openconnector', key)
		},
	},
}
</script>
