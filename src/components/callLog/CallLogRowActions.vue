<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CallLogRowActions: the per-row actions on the outbound call log.

  Wired through `pages[].slots["row-actions"]` on SourceLogs; CnPageRenderer
  hands it the row. An inbound row has nothing to replay, so it gets no
  actions. Replaying opens CallReplayModal, which previews first.

  @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
-->
<template>
	<div v-if="replayable" class="callLogRowActions">
		<NcActions :aria-label="t('integriq', 'Call actions')">
			<NcActionButton
				:closeAfterClick="true"
				data-testid="call-log-row-replay"
				@click="replayOpen = true">
				<template #icon>
					<ReplayIcon :size="20" />
				</template>
				{{ t('integriq', 'Replay') }}
			</NcActionButton>
		</NcActions>
		<CallReplayModal
			:open="replayOpen"
			:callId="id"
			@replayed="onReplayed"
			@close="replayOpen = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import ReplayIcon from 'vue-material-design-icons/Replay.vue'
import CallReplayModal from '../../modals/CallLog/CallReplayModal.vue'
import { callId } from '../../modals/CallLog/callLogApi.js'
import { refreshLogPage } from './refreshLogPage.js'

export default {
	name: 'CallLogRowActions',

	components: { CallReplayModal, NcActionButton, NcActions, ReplayIcon },

	props: {
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			replayOpen: false,
		}
	},

	computed: {
		/**
		 * The call record uuid.
		 *
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		id() {
			return callId(this.row)
		},

		/**
		 * Whether this row is an outbound call there is something to replay of.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		replayable() {
			return this.id !== '' && this.row?.direction !== 'inbound'
		},
	},

	methods: {
		t,

		/**
		 * Show the new attempt in the list.
		 *
		 * @return {void}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		onReplayed() {
			refreshLogPage(this)
		},
	},
}
</script>
