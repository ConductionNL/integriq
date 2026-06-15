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
		<SubscriptionSigningModal
			:open="subscriptionSigning.open"
			:subscription="subscriptionSigning.subscription"
			@close="closeSubscriptionSigning" />
	</div>
</template>

<script>
import TestMappingModal from './TestMappingModal.vue'
import AddEndpointRuleModal from './AddEndpointRuleModal.vue'
import SubscriptionSigningModal from '../Subscription/SubscriptionSigningModal.vue'
import {
	modalBus,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_ADD_ENDPOINT_RULE,
	EVENT_OPEN_SUBSCRIPTION_SIGNING,
} from '../../handlers/modalBus.js'

export default {
	name: 'ModalHost',

	components: {
		TestMappingModal,
		AddEndpointRuleModal,
		SubscriptionSigningModal,
	},

	data() {
		return {
			testMapping: { open: false, mapping: null },
			addEndpointRule: { open: false, endpoint: null },
			subscriptionSigning: { open: false, subscription: null },
		}
	},

	/** @spec openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/tasks.md#task-2 */
	mounted() {
		modalBus.$on(EVENT_OPEN_TEST_MAPPING, this.openTestMapping)
		modalBus.$on(EVENT_OPEN_ADD_ENDPOINT_RULE, this.openAddEndpointRule)
		modalBus.$on(EVENT_OPEN_SUBSCRIPTION_SIGNING, this.openSubscriptionSigning)
	},

	/** @spec openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/tasks.md#task-2 */
	beforeDestroy() {
		modalBus.$off(EVENT_OPEN_TEST_MAPPING, this.openTestMapping)
		modalBus.$off(EVENT_OPEN_ADD_ENDPOINT_RULE, this.openAddEndpointRule)
		modalBus.$off(EVENT_OPEN_SUBSCRIPTION_SIGNING, this.openSubscriptionSigning)
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/tasks.md#task-2 */
		openTestMapping(payload) {
			this.testMapping = { open: true, mapping: payload?.mapping ?? null }
		},
		/** @spec openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/tasks.md#task-2 */
		closeTestMapping() {
			this.testMapping = { open: false, mapping: null }
		},
		/** @spec openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/tasks.md#task-2 */
		openAddEndpointRule(payload) {
			this.addEndpointRule = { open: true, endpoint: payload?.endpoint ?? null }
		},
		/** @spec openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/tasks.md#task-2 */
		closeAddEndpointRule() {
			this.addEndpointRule = { open: false, endpoint: null }
		},
		/** @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5 */
		openSubscriptionSigning(payload) {
			this.subscriptionSigning = { open: true, subscription: payload?.subscription ?? null }
		},
		/** @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5 */
		closeSubscriptionSigning() {
			this.subscriptionSigning = { open: false, subscription: null }
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
