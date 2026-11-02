<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  DeadLetterBulkBar: the bulk replay and discard bar of the dead-letter
  pages. The page passes its BEM block name for the class and the two
  translated confirmation prompts, and owns the confirm state and the commit.

  @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
-->
<template>
	<div :class="`${block}__bulk`" data-testid="bulk-bar">
		<span>{{ t('integriq', '{count} selected', { count }) }}</span>
		<template v-if="confirming">
			<span>{{ confirming === 'replay' ? replayPrompt : discardPrompt }}</span>
			<NcButton variant="primary" :disabled="busy" @click="$emit('commit')">
				{{ t('integriq', 'Confirm') }}
			</NcButton>
			<NcButton :disabled="busy" @click="$emit('confirm', null)">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
		</template>
		<template v-else>
			<NcButton
				variant="primary"
				:disabled="busy"
				@click="$emit('confirm', 'replay')">
				{{ t('integriq', 'Replay selected') }}
			</NcButton>
			<NcButton :disabled="busy" @click="$emit('confirm', 'discard')">
				{{ t('integriq', 'Discard selected') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'DeadLetterBulkBar',

	components: { NcButton },

	props: {
		/** BEM block of the page, used for the bar's class. */
		block: { type: String, required: true },
		/** How many rows are selected. */
		count: { type: Number, required: true },
		/** The verb awaiting confirmation, or null. */
		confirming: { type: String, default: null },
		/** Whether a bulk run is in flight. */
		busy: { type: Boolean, default: false },
		/** The translated replay confirmation. */
		replayPrompt: { type: String, required: true },
		/** The translated discard confirmation. */
		discardPrompt: { type: String, required: true },
	},

	emits: ['confirm', 'commit'],

	methods: { t },
}
</script>
