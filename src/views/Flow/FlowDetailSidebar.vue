<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  The flow controls, in Nextcloud's app sidebar. Shares `useFlowStore` with the
  canvas, which is why neither needs props from the other. Mirrors
  openregister/src/views/flows/FlowDetailSidebar.vue exactly — one shared store,
  every app's sidebar behaves identically.

  @spec openspec/specs/flow-orchestration/spec.md#REQ-017
-->
<template>
	<CnFlowSidebar @save="onSave" @run="onRun" />
</template>

<script>
import { CnFlowSidebar, useFlowStore } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailSidebar',

	components: { CnFlowSidebar },

	/**
	 * Share the one flow store with the save/run handlers.
	 *
	 * @return {object} The setup bindings.
	 * @spec openspec/specs/flow-orchestration/spec.md#REQ-017
	 */
	setup() {
		return { store: useFlowStore() }
	},

	// Transition wiring: on @conduction/nextcloud-vue 2.4+ Save/Run live on
	// CnFlowDetail's toolbar and CnFlowSidebar never emits these, so the
	// handlers are inert; on 2.3.x the sidebar's buttons are the only Save/Run
	// there is, and dropping the handlers would leave them dead. Remove once
	// the fleet's lockfiles are past 2.4.
	methods: {
		/**
		 * @spec openspec/specs/flow-orchestration/spec.md#REQ-017
		 * @return {Promise<void>}
		 */
		async onSave() {
			const saved = await this.store.save()
			// A newly created flow gets its id from the server, so the route has
			// to catch up or a reload would land back on `new`.
			if (saved?.id && this.$route.params.id === 'new') {
				this.$router.replace(`/flows/${saved.id}`)
			}
		},

		/**
		 * @spec openspec/specs/flow-orchestration/spec.md#REQ-017
		 * @return {Promise<void>}
		 */
		async onRun() {
			await this.store.run({})
		},
	},
}
</script>
