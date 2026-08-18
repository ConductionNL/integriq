<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FlowDetailPage — the flow canvas, scoped to this app's flows.

  Replaces the bespoke ordered step-list editor (visual-flow-orchestration
  REQ-009's explicit v1 constraint: "no drag-and-drop, no canvas"). That
  constraint is superseded by flow-engine-unification task 6.2: OpenConnector
  adopts the SAME shared `CnFlowDetail` canvas OpenRegister and hermiq already
  use, over the same native flow store — not a second, app-owned flow surface.

  Controls live in FlowDetailSidebar, rendered into Nextcloud's app sidebar by
  the manifest's `sidebarComponent`, so the canvas keeps the full width —
  mirrors openregister/src/views/flows/FlowDetailPage.vue exactly.

  The requirement is `flow-orchestration` REQ-017 — this app's own adoption of
  the shared canvas. OpenRegister's `flow-engine-unification` change, which the
  tag here used to name, lives in OpenRegister's repository and cannot be
  reached by a repository-relative `@spec` path from here.

  @spec openspec/specs/flow-orchestration/spec.md#REQ-017
-->
<template>
	<CnFlowDetail :id="$route.params.id" app="openconnector" @save="onSave" @run="onRun" />
</template>

<script>
import { CnFlowDetail, useFlowStore } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailPage',
	components: { CnFlowDetail },

	setup() {
		return { store: useFlowStore() }
	},

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
