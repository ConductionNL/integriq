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
       RunActionModal depends on this: a synchronization run can outlive
       several seconds of navigation, and its result must survive to be read.
    2. Each handler stays a plain function with no Vue-instance context —
       it just calls `modalBus.emit('open-foo', { item })` and the host
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
		<TestSourceModal
			:open="testSource.open"
			:source="testSource.source"
			@close="closeTestSource" />
		<AddEndpointRuleModal
			:open="addEndpointRule.open"
			:endpoint="addEndpointRule.endpoint"
			@close="closeAddEndpointRule" />
		<RunActionModal
			:open="runAction.open"
			:target="runAction.target"
			:mode="runAction.mode"
			:item="runAction.item"
			@close="closeRunAction" />
		<SubscriptionSigningModal
			:open="subscriptionSigning.open"
			:subscription="subscriptionSigning.subscription"
			@close="closeSubscriptionSigning" />
		<CatalogItemDetailDialog
			:open="catalogItemDetail.open"
			:item="catalogItemDetail.item"
			@close="closeCatalogItemDetail" />
		<ImportPreviewDialog
			:open="configurationImport.open"
			@close="closeConfigurationImport" />
		<ExportConfigurationDialog
			:open="configurationExport.open"
			@close="closeConfigurationExport" />
		<PromotePreviewModal :open="promotion.open" @close="closePromotion" />
	</div>
</template>

<script>
import CatalogItemDetailDialog from '../../dialogs/CatalogItemDetailDialog.vue'
import ExportConfigurationDialog from '../../dialogs/ExportConfigurationDialog.vue'
import ImportPreviewDialog from '../../dialogs/ImportPreviewDialog.vue'
import PromotePreviewModal from '../PromotePreviewModal.vue'
import SubscriptionSigningModal from '../Subscription/SubscriptionSigningModal.vue'
import AddEndpointRuleModal from './AddEndpointRuleModal.vue'
import RunActionModal from './RunActionModal.vue'
import TestMappingModal from './TestMappingModal.vue'
import TestSourceModal from './TestSourceModal.vue'
import {
	EVENT_OPEN_ADD_ENDPOINT_RULE,
	EVENT_OPEN_CATALOG_ITEM_DETAIL,
	EVENT_OPEN_CONFIGURATION_EXPORT,
	EVENT_OPEN_CONFIGURATION_IMPORT,
	EVENT_OPEN_PROMOTION,
	EVENT_OPEN_RUN_ACTION,
	EVENT_OPEN_SUBSCRIPTION_SIGNING,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_TEST_SOURCE,
	modalBus,
} from '../../handlers/modalBus.js'

export default {
	name: 'ModalHost',

	components: {
		TestMappingModal,
		TestSourceModal,
		AddEndpointRuleModal,
		RunActionModal,
		SubscriptionSigningModal,
		CatalogItemDetailDialog,
		ImportPreviewDialog,
		ExportConfigurationDialog,
		PromotePreviewModal,
	},

	data() {
		return {
			testMapping: { open: false, mapping: null },
			testSource: { open: false, source: null },
			addEndpointRule: { open: false, endpoint: null },
			runAction: { open: false, target: '', mode: '', item: null },
			subscriptionSigning: { open: false, subscription: null },
			catalogItemDetail: { open: false, item: null },
			configurationImport: { open: false },
			configurationExport: { open: false },
			promotion: { open: false },
		}
	},

	/** @spec openspec/specs/app-shell-and-logs-ui/spec.md */
	mounted() {
		modalBus.on(EVENT_OPEN_TEST_MAPPING, this.openTestMapping)
		modalBus.on(EVENT_OPEN_TEST_SOURCE, this.openTestSource)
		modalBus.on(EVENT_OPEN_ADD_ENDPOINT_RULE, this.openAddEndpointRule)
		modalBus.on(EVENT_OPEN_RUN_ACTION, this.openRunAction)
		modalBus.on(EVENT_OPEN_SUBSCRIPTION_SIGNING, this.openSubscriptionSigning)
		modalBus.on(EVENT_OPEN_CATALOG_ITEM_DETAIL, this.openCatalogItemDetail)
		modalBus.on(EVENT_OPEN_CONFIGURATION_IMPORT, this.openConfigurationImport)
		modalBus.on(EVENT_OPEN_CONFIGURATION_EXPORT, this.openConfigurationExport)
		modalBus.on(EVENT_OPEN_PROMOTION, this.openPromotion)
	},

	/** @spec openspec/specs/app-shell-and-logs-ui/spec.md */
	beforeUnmount() {
		modalBus.off(EVENT_OPEN_TEST_MAPPING, this.openTestMapping)
		modalBus.off(EVENT_OPEN_TEST_SOURCE, this.openTestSource)
		modalBus.off(EVENT_OPEN_ADD_ENDPOINT_RULE, this.openAddEndpointRule)
		modalBus.off(EVENT_OPEN_RUN_ACTION, this.openRunAction)
		modalBus.off(EVENT_OPEN_SUBSCRIPTION_SIGNING, this.openSubscriptionSigning)
		modalBus.off(EVENT_OPEN_CATALOG_ITEM_DETAIL, this.openCatalogItemDetail)
		modalBus.off(EVENT_OPEN_CONFIGURATION_IMPORT, this.openConfigurationImport)
		modalBus.off(EVENT_OPEN_CONFIGURATION_EXPORT, this.openConfigurationExport)
		modalBus.off(EVENT_OPEN_PROMOTION, this.openPromotion)
	},

	methods: {
		/**
		 * @param payload
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md
		 */
		openTestMapping(payload) {
			this.testMapping = { open: true, mapping: payload?.mapping ?? null }
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md */
		closeTestMapping() {
			this.testMapping = { open: false, mapping: null }
		},

		/**
		 * @param payload
		 * @spec openspec/specs/http-call-engine/spec.md
		 */
		openTestSource(payload) {
			this.testSource = { open: true, source: payload?.source ?? null }
		},

		/** @spec openspec/specs/http-call-engine/spec.md */
		closeTestSource() {
			this.testSource = { open: false, source: null }
		},

		/**
		 * @param payload
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md
		 */
		openAddEndpointRule(payload) {
			this.addEndpointRule = {
				open: true,
				endpoint: payload?.endpoint ?? null,
			}
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md */
		closeAddEndpointRule() {
			this.addEndpointRule = { open: false, endpoint: null }
		},

		/**
		 * @param payload
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		openRunAction(payload) {
			this.runAction = {
				open: true,
				target: payload?.target ?? '',
				mode: payload?.mode ?? '',
				item: payload?.item ?? null,
			}
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		closeRunAction() {
			this.runAction = { open: false, target: '', mode: '', item: null }
		},

		/**
		 * @param payload
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		openSubscriptionSigning(payload) {
			this.subscriptionSigning = {
				open: true,
				subscription: payload?.subscription ?? null,
			}
		},

		/** @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5 */
		closeSubscriptionSigning() {
			this.subscriptionSigning = { open: false, subscription: null }
		},

		/**
		 * @param payload
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		openCatalogItemDetail(payload) {
			this.catalogItemDetail = { open: true, item: payload?.item ?? null }
		},

		/** @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002 */
		closeCatalogItemDetail() {
			this.catalogItemDetail = { open: false, item: null }
		},

		/** @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything */
		openConfigurationImport() {
			this.configurationImport = { open: true }
		},

		/** @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything */
		closeConfigurationImport() {
			this.configurationImport = { open: false }
		},

		/** @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui */
		openConfigurationExport() {
			this.configurationExport = { open: true }
		},

		/** @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui */
		closeConfigurationExport() {
			this.configurationExport = { open: false }
		},

		/** @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003 */
		openPromotion() {
			this.promotion = { open: true }
		},

		/** @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003 */
		closePromotion() {
			this.promotion = { open: false }
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
