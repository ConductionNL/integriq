<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IntakeMessageRowActions: "Route again" on a held or failed message in the
  intake inbox.

  Wired through `pages[].slots["row-actions"]` on IntakeMessages. Routing runs
  once, on arrival, so a message held before its rule existed stays held until
  someone chooses this. It posts to `POST /api/intake/messages/{id}/route`,
  which runs the same match and dispatch as a new arrival and writes the
  outcome onto the same message, then reads the inbox again.

  @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
-->
<template>
	<div v-if="routable" class="intakeMessageRowActions">
		<NcActions :aria-label="t('integriq', 'Message actions')">
			<NcActionButton
				:closeAfterClick="true"
				:disabled="routing"
				data-testid="intake-message-route-again"
				@click="onRouteAgain">
				<template #icon>
					<SignDirectionIcon :size="20" />
				</template>
				{{ t('integriq', 'Route again') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import SignDirectionIcon from 'vue-material-design-icons/SignDirection.vue'
import { refusalOf, routeAgain } from '../../modals/v2/intakeRuleApi.js'
import { ruleId } from '../../modals/v2/intakeRuleDraft.js'
import { refreshLogPage } from '../callLog/refreshLogPage.js'
import { describeOutcome, isRoutable } from './routeAgainOutcome.js'

export default {
	name: 'IntakeMessageRowActions',

	components: { NcActionButton, NcActions, SignDirectionIcon },

	props: {
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			routing: false,
		}
	},

	computed: {
		/**
		 * Whether this message can be routed again.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		routable() {
			return isRoutable(this.row) && ruleId(this.row) !== ''
		},
	},

	methods: {
		t,

		/**
		 * Route the message again and say what happened.
		 *
		 * @return {Promise<boolean>} Whether it was routed.
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		async onRouteAgain() {
			this.routing = true
			let outcome
			try {
				outcome = describeOutcome(await routeAgain(ruleId(this.row)))
			} catch (error) {
				outcome = { routed: false, text: refusalOf(error, t('integriq', 'The message could not be routed again.')) }
			} finally {
				this.routing = false
			}

			if (outcome.routed) {
				showSuccess(outcome.text)
			} else {
				showError(outcome.text)
			}
			refreshLogPage(this)
			return outcome.routed
		},
	},
}
</script>
