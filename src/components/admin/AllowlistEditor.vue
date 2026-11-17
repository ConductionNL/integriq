<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  AllowlistEditor: one allowlist section of integriq's admin settings page.
  It shows the entries with who added each and when, a remove button per
  entry and a field to add one. The parent owns the list and the requests
  (see the allowlistEditor mixin); this component only shows them and emits
  `add` and `remove`.

  @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
-->
<template>
	<div class="integriq-admin__section" :data-testid="`${testid}-section`">
		<h3>{{ title }}</h3>
		<p class="integriq-admin__hint">
			{{ hint }}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the allowlist…') }}
		</p>

		<template v-else>
			<p
				v-if="entries.length === 0"
				class="integriq-admin__hint"
				:data-testid="`${testid}-empty`">
				{{ emptyText }}
			</p>
			<table
				v-else
				class="allowlist-editor__table"
				:data-testid="`${testid}-list`">
				<thead>
					<tr>
						<th scope="col">
							{{ column }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Added by') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Added on') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{
								t('integriq', 'Actions')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="entry in entries"
						:key="entry[field]"
						:data-testid="`${testid}-row`">
						<td>
							<code>{{ entry[field] }}</code>
						</td>
						<td>{{ entry.addedBy }}</td>
						<td>{{ entry.addedAt }}</td>
						<td>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:aria-label="removeLabel(entry[field])"
								@click="$emit('remove', entry[field])">
								{{ t('integriq', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="allowlist-editor__add">
				<NcTextField
					:modelValue="modelValue"
					:data-testid="`${testid}-${field}`"
					:label="inputLabel"
					@update:modelValue="$emit('update:modelValue', $event)"
					@keyup.enter="$emit('add')" />
				<NcButton
					variant="primary"
					:data-testid="`${testid}-add`"
					:disabled="busy || modelValue.trim() === ''"
					@click="$emit('add')">
					{{ t('integriq', 'Add to the list') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AllowlistEditor',

	components: {
		NcButton,
		NcTextField,
	},

	props: {
		/** Prefix of every data-testid in the section. */
		testid: { type: String, required: true },
		/** The entry field that names an entry, e.g. key or schema. */
		field: { type: String, required: true },
		/** The entries: { [field], addedBy, addedAt }. */
		entries: { type: Array, required: true },
		/** The text in the add field. */
		modelValue: { type: String, default: '' },
		busy: { type: Boolean, default: false },
		loading: { type: Boolean, default: false },
		error: { type: String, default: '' },
		title: { type: String, required: true },
		hint: { type: String, required: true },
		emptyText: { type: String, required: true },
		/** Header of the column that names an entry. */
		column: { type: String, required: true },
		inputLabel: { type: String, required: true },
		/** The remove button's accessible name for one entry. */
		removeLabel: { type: Function, required: true },
	},

	emits: ['add', 'remove', 'update:modelValue'],

	methods: {
		t,
	},
}
</script>

<style scoped>
.allowlist-editor__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.allowlist-editor__table th,
.allowlist-editor__table td {
	text-align: start;
	padding: 4px 8px;
}

.allowlist-editor__add {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 560px;
}

.allowlist-editor__add > :first-child {
	flex: 1 1 auto;
}
</style>
