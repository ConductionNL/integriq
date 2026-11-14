<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IntakeMessageActions: the intake inbox's page action to route a selection of
  held and failed messages again.

  Wired through `pages[].actionsComponent` on IntakeMessages. The dialog lists
  the waiting messages, routes the chosen ones in one request and answers each
  one; then the inbox is read again.

  @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
-->
<template>
	<div class="intakeMessageActions">
		<NcButton data-testid="intake-messages-route-again" @click="open = true">
			<template #icon>
				<SignDirectionIcon :size="20" />
			</template>
			{{ t('integriq', 'Route held messages again') }}
		</NcButton>
		<IntakeRouteAgainModal
			:open="open"
			@routed="refresh"
			@close="open = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import SignDirectionIcon from 'vue-material-design-icons/SignDirection.vue'
import IntakeRouteAgainModal from '../../modals/Intake/IntakeRouteAgainModal.vue'
import { refreshLogPage } from '../callLog/refreshLogPage.js'

export default {
	name: 'IntakeMessageActions',

	components: { IntakeRouteAgainModal, NcButton, SignDirectionIcon },

	data() {
		return {
			open: false,
		}
	},

	methods: {
		t,

		/**
		 * Show the new statuses in the inbox.
		 *
		 * @return {void}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		refresh() {
			refreshLogPage(this)
		},
	},
}
</script>

<style scoped>
.intakeMessageActions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
