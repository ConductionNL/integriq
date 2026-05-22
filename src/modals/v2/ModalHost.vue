<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  ModalHost — single mount point for openconnector's row-action modals.

  Mounted once near the App.vue root, ModalHost subscribes to the shared
  src/handlers/modalBus.js events and renders the corresponding modal
  with the payload carried on the event. Keeping a single host (rather
  than per-page mounts) means:

    1. The modals live outside the manifest-rendered router-view tree, so
       a page swap mid-test cannot unmount the modal from under the user.
    2. Each handler stays a plain function with no Vue-instance context —
       it just calls `modalBus.$emit('open-foo', { item })` and the host
       picks it up.

  Adding a new modal here is three lines: import the SFC, register an
  event name in modalBus, and add a `<MyModal ... />` render block plus
  the matching `data()` slot.
-->
<template>
	<div class="cn-modal-host" data-testid="cn-modal-host">
		<TestMappingModal
			:open="testMapping.open"
			:mapping="testMapping.mapping"
			@close="closeTestMapping" />
		<AddEndpointRuleModal
			:open="addEndpointRule.open"
			:endpoint="addEndpointRule.endpoint"
			@close="closeAddEndpointRule" />
	</div>
</template>

<script>
import TestMappingModal from './TestMappingModal.vue'
import AddEndpointRuleModal from './AddEndpointRuleModal.vue'
import {
	modalBus,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_ADD_ENDPOINT_RULE,
} from '../../handlers/modalBus.js'

export default {
	name: 'ModalHost',

	components: {
		TestMappingModal,
		AddEndpointRuleModal,
	},

	data() {
		return {
			testMapping: { open: false, mapping: null },
			addEndpointRule: { open: false, endpoint: null },
		}
	},

	mounted() {
		modalBus.$on(EVENT_OPEN_TEST_MAPPING, this.openTestMapping)
		modalBus.$on(EVENT_OPEN_ADD_ENDPOINT_RULE, this.openAddEndpointRule)
	},

	beforeDestroy() {
		modalBus.$off(EVENT_OPEN_TEST_MAPPING, this.openTestMapping)
		modalBus.$off(EVENT_OPEN_ADD_ENDPOINT_RULE, this.openAddEndpointRule)
	},

	methods: {
		openTestMapping(payload) {
			this.testMapping = { open: true, mapping: payload?.mapping ?? null }
		},
		closeTestMapping() {
			this.testMapping = { open: false, mapping: null }
		},
		openAddEndpointRule(payload) {
			this.addEndpointRule = { open: true, endpoint: payload?.endpoint ?? null }
		},
		closeAddEndpointRule() {
			this.addEndpointRule = { open: false, endpoint: null }
		},
	},
}
</script>

<style scoped>
.cn-modal-host {
	/* Host is invisible; modals teleport to body via NcModal. */
	display: contents;
}
</style>
