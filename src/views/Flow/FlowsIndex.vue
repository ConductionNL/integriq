<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Flows — OpenConnector's scoped view over the one shared flow store.

  Passes `app="openconnector"`: this app sees only its own flows. OpenRegister's
  own Flows page passes no app filter and shows every app's flows (that surface
  is the fleet-wide one; this is the leaf-app one). Mirrors
  openregister/src/views/flows/FlowsIndex.vue exactly.

  @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
-->
<template>
	<CnFlowIndexPage app="openconnector" @open="openFlow" @create="createFlow" />
</template>

<script>
import { CnFlowIndexPage } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowsIndex',

	components: { CnFlowIndexPage },

	methods: {
		/**
		 * @param {object} flow The activated flow.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {void}
		 */
		openFlow(flow) {
			this.$router.push(`/flows/${flow.id}`)
		},

		/**
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {void}
		 */
		createFlow() {
			this.$router.push('/flows/new')
		},
	},
}
</script>
