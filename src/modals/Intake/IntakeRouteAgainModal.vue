<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IntakeRouteAgainModal: route a selection of held and failed intake messages
  again, answering each one.

  Opened from the intake inbox's page action. It lists the waiting messages,
  posts the chosen ids to `POST /api/intake/messages/route` and shows the
  outcome next to each message: routed with the rule and the case, or why it
  is still held. Nothing is routed until the administrator chooses to; saving
  a rule never routes held messages by itself.

  @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
-->
<template>
	<NcModal
		v-if="open"
		labelId="intake-route-again-title"
		data-testid="intake-route-again-modal"
		@close="$emit('close')">
		<div class="intakeRouteAgain">
			<h2 id="intake-route-again-title">
				{{ t('integriq', 'Route held messages again') }}
			</h2>

			<p v-if="loading">
				{{ t('integriq', 'Loading the held messages') }}
			</p>
			<p v-else-if="error" class="intakeRouteAgain__error" role="alert">
				{{ error }}
			</p>
			<p v-else-if="messages.length === 0" data-testid="intake-route-again-empty">
				{{ t('integriq', 'No message is held. There is nothing to route again.') }}
			</p>

			<ul v-else class="intakeRouteAgain__list" data-testid="intake-route-again-list">
				<li v-for="message in messages" :key="idOf(message)">
					<NcCheckboxRadioSwitch
						:modelValue="selected.includes(idOf(message))"
						:disabled="busy"
						@update:modelValue="toggle(idOf(message), $event)">
						{{ labelOf(message) }}
					</NcCheckboxRadioSwitch>
					<span
						v-if="outcomes[idOf(message)]"
						class="intakeRouteAgain__outcome"
						:class="outcomes[idOf(message)].routed ? 'intakeRouteAgain__outcome--ok' : 'intakeRouteAgain__outcome--held'"
						data-testid="intake-route-again-item-outcome">
						{{ outcomes[idOf(message)].text }}
					</span>
				</li>
			</ul>

			<p v-if="summary" role="status" data-testid="intake-route-again-summary">
				{{ summary }}
			</p>

			<div class="intakeRouteAgain__actions">
				<NcButton
					variant="primary"
					:disabled="selected.length === 0 || busy"
					data-testid="intake-route-again-send"
					@click="routeSelected">
					{{ n('integriq', 'Route %n message again', 'Route %n messages again', selected.length) }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('integriq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'
import { describeOutcome } from '../../components/intake/routeAgainOutcome.js'
import { fetchWaitingMessages, refusalOf, routeAgainMany } from '../v2/intakeRuleApi.js'
import { ruleId } from '../v2/intakeRuleDraft.js'

export default {
	name: 'IntakeRouteAgainModal',

	components: { NcButton, NcCheckboxRadioSwitch, NcModal },

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'routed'],

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			messages: [],
			selected: [],
			outcomes: {},
			summary: '',
		}
	},

	watch: {
		open: {
			immediate: true,
			handler(value) {
				if (value) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,
		n,

		/**
		 * Load the held and failed messages.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.selected = []
			this.outcomes = {}
			this.summary = ''
			try {
				this.messages = await fetchWaitingMessages()
			} catch (error) {
				this.messages = []
				this.error = refusalOf(error, t('integriq', 'The held messages could not be loaded.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * The uuid of a message.
		 *
		 * @param {object} message The message.
		 * @return {string}
		 */
		idOf(message) {
			return ruleId(message)
		},

		/**
		 * How a message is named in the list.
		 *
		 * @param {object} message The message.
		 * @return {string}
		 */
		labelOf(message) {
			const textPart = String(message.text || message.externalId || this.idOf(message)).slice(0, 80)
			return '[' + (message.channelId || '?') + '] ' + textPart
		},

		/**
		 * Select or deselect a message.
		 *
		 * @param {string} id The uuid.
		 * @param {boolean} checked Whether it is selected.
		 * @return {void}
		 */
		toggle(id, checked) {
			this.selected = checked
				? [...new Set([...this.selected, id])]
				: this.selected.filter((selectedId) => selectedId !== id)
		},

		/**
		 * Route the selection again and show each outcome.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
		 */
		async routeSelected() {
			this.busy = true
			this.error = ''
			try {
				const results = await routeAgainMany(this.selected)
				const outcomes = {}
				for (const result of results) {
					outcomes[String(result.id)] = describeOutcome(result)
				}
				this.outcomes = outcomes
				const routed = Object.values(outcomes).filter((outcome) => outcome.routed).length
				this.summary = n('integriq', '%n message routed', '%n messages routed', routed)
					+ ', ' + n('integriq', '%n still waiting', '%n still waiting', results.length - routed)
				this.selected = this.selected.filter((id) => outcomes[id]?.routed !== true)
				this.$emit('routed', results)
			} catch (error) {
				this.error = refusalOf(error, t('integriq', 'The messages could not be routed again.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.intakeRouteAgain {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.intakeRouteAgain h2 {
	margin: 0;
}

.intakeRouteAgain__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-height: 50vh;
	overflow-y: auto;
}

.intakeRouteAgain__outcome {
	display: block;
	padding-inline-start: calc(var(--default-grid-baseline) * 9);
	color: var(--color-text-maxcontrast);
}

.intakeRouteAgain__outcome--ok {
	color: var(--color-success-text, var(--color-success));
}

.intakeRouteAgain__outcome--held {
	color: var(--color-warning-text, var(--color-warning));
}

.intakeRouteAgain__error {
	color: var(--color-error-text, var(--color-error));
}

.intakeRouteAgain__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>
