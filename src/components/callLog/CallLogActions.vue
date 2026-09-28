<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CallLogActions: the page actions on the outbound call log.

  Wired through `pages[].actionsComponent` on SourceLogs. Two verbs that are
  not about one row: replay the calls that failed, several at once with an
  outcome each, and fire a call by hand that has not happened yet.

  @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
-->
<template>
	<div class="callLogActions">
		<NcButton data-testid="call-log-bulk-replay" @click="bulkOpen = true">
			<template #icon>
				<ReplayIcon :size="20" />
			</template>
			{{ t('integriq', 'Replay failed calls') }}
		</NcButton>
		<NcButton data-testid="call-log-fire" @click="fireOpen = true">
			<template #icon>
				<SendIcon :size="20" />
			</template>
			{{ t('integriq', 'Fire a call by hand') }}
		</NcButton>
		<CallBulkReplayModal
			:open="bulkOpen"
			@replayed="refresh"
			@close="bulkOpen = false" />
		<CallFireModal :open="fireOpen" @fired="refresh" @close="fireOpen = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ReplayIcon from 'vue-material-design-icons/Replay.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import CallBulkReplayModal from '../../modals/CallLog/CallBulkReplayModal.vue'
import CallFireModal from '../../modals/CallLog/CallFireModal.vue'
import { refreshLogPage } from './refreshLogPage.js'

export default {
	name: 'CallLogActions',

	components: {
		CallBulkReplayModal,
		CallFireModal,
		NcButton,
		ReplayIcon,
		SendIcon,
	},

	data() {
		return {
			bulkOpen: false,
			fireOpen: false,
		}
	},

	methods: {
		t,

		/**
		 * Show what the replay or the hand-fired call added to the log.
		 *
		 * @return {void}
		 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		refresh() {
			refreshLogPage(this)
		},
	},
}
</script>

<style scoped>
.callLogActions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
