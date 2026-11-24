<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  ScopeLockoutDialog — the rule editor's save dialog when the edit adds a
  required scope (access-oauth-and-token-validation, REQ-TOKV-005): it names
  the consumers that lose access, and the administrator cancels or saves
  anyway. RuleDetailPage works out the list (scopeLockout.js).
-->
<template>
	<NcDialog
		:name="t('integriq', 'Consumers that lose access')"
		data-testid="rule-scope-lockout"
		@closing="$emit('cancel')">
		<p>
			{{
				fromCallLog
					? t('integriq', 'These consumers call an endpoint that uses this rule and do not hold a scope you are adding. After saving, their calls get 403.')
					: t('integriq', 'No calls to the endpoints of this rule are recorded, so this lists every consumer that does not hold a scope you are adding. After saving, their calls get 403.')
			}}
		</p>
		<ul class="scope-lockout__list">
			<li
				v-for="consumer in consumers"
				:key="consumer.id"
				data-testid="rule-scope-lockout-consumer">
				<strong>{{ consumer.name }}</strong>
				{{ t('integriq', 'lacks {scopes}', { scopes: consumer.missing.join(', ') }) }}
			</li>
		</ul>
		<template #actions>
			<NcButton variant="secondary" data-testid="rule-scope-lockout-cancel" @click="$emit('cancel')">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" data-testid="rule-scope-lockout-confirm" @click="$emit('confirm')">
				{{ t('integriq', 'Save anyway') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

/**
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
export default {
	name: 'ScopeLockoutDialog',

	components: { NcButton, NcDialog },

	props: {
		/** The consumers locked out: `{id, name, missing}`. */
		consumers: { type: Array, required: true },
		/** True when the list comes from the call log, false when it is every consumer lacking the scope. */
		fromCallLog: { type: Boolean, default: false },
	},

	emits: ['cancel', 'confirm'],
}
</script>

<style scoped>
.scope-lockout__list {
	margin: 8px 0 0 20px;
	list-style: disc;
}
</style>
