<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MappingCallableByField: which apps may run this mapping through an event
  (mapping-woo-index-field-mapping REQ-WOOM-002). An empty list lets no app
  in. Saved with the mapping, so it needs the same right as its rules.

  @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
-->
<template>
	<div class="callable-by" data-testid="mapping-callable-by">
		<NcSelect
			:modelValue="apps"
			:inputLabel="t('integriq', 'Apps that may run this mapping')"
			:options="apps"
			:multiple="true"
			:taggable="true"
			:disabled="disabled"
			@update:modelValue="onChange" />
		<p class="callable-by__hint">
			{{
				apps.length === 0
					? t('integriq', 'No other app can run this mapping.')
					: t('integriq', 'These apps can run this mapping by its slug.')
			}}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { normaliseCallableBy } from './callableBy.js'

export default {
	name: 'MappingCallableByField',

	components: { NcSelect },

	props: {
		value: {
			type: Array,
			default: () => [],
		},

		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update'],

	computed: {
		/**
		 * The stored list, normalised.
		 *
		 * @return {string[]}
		 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
		 */
		apps() {
			return normaliseCallableBy(this.value)
		},
	},

	methods: {
		t,

		/**
		 * Emit the edited list, normalised.
		 *
		 * @param {Array} next The selection.
		 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
		 */
		onChange(next) {
			this.$emit('update', normaliseCallableBy(next))
		},
	},
}
</script>

<style scoped>
.callable-by__hint {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
</style>
