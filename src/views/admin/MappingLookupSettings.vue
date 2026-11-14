<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  MappingLookupSettings: the lookup allowlist on integriq's admin settings
  page (mapping-formats-and-lookups task 5).

  A mapping's lookup() reads a register schema only when an administrator has
  listed it here as register/schema. The list shows each schema with who added
  it and when. The backend refuses anyone but an instance administrator
  (/api/admin/mapping-lookups) and records every change.

  @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
-->
<template>
	<div class="integriq-admin__section" data-testid="admin-mapping-lookups-section">
		<h3>{{ t('integriq', 'Register schemas a mapping may look up in') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'A mapping can translate a code with lookup() only in a schema on this list. Every change is logged with your name.',
				)
			}}
		</p>
		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>
		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the allowlist…') }}
		</p>
		<template v-else>
			<p
				v-if="schemas.length === 0"
				class="integriq-admin__hint"
				data-testid="admin-mapping-lookups-empty">
				{{
					t(
						'integriq',
						'No schema is listed, so a mapping can look up in none.',
					)
				}}
			</p>
			<table
				v-else
				class="mapping-lookups__table"
				data-testid="admin-mapping-lookups-list">
				<thead>
					<tr>
						<th scope="col">
							{{ t('integriq', 'Schema') }}
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
						v-for="entry in schemas"
						:key="entry.schema"
						data-testid="admin-mapping-lookups-row">
						<td>
							<code>{{ entry.schema }}</code>
						</td>
						<td>{{ entry.addedBy }}</td>
						<td>{{ entry.addedAt }}</td>
						<td>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:aria-label="
									t('integriq', 'Remove {schema}', {
										schema: entry.schema,
									})
								"
								@click="remove(entry.schema)">
								{{ t('integriq', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="mapping-lookups__add">
				<NcTextField
					v-model="newSchema"
					data-testid="admin-mapping-lookups-schema"
					:label="t('integriq', 'Schema (register/schema)')"
					@keyup.enter="add" />
				<NcButton
					variant="primary"
					data-testid="admin-mapping-lookups-add"
					:disabled="busy || newSchema.trim() === ''"
					@click="add">
					{{ t('integriq', 'Add to the list') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'

const API = '/apps/integriq/api/admin/mapping-lookups'

/**
 * The address that removes one schema from the list.
 *
 * @param {string} schema The schema, as register/schema.
 * @return {string} The URL.
 */
export function removeUrl(schema) {
	const [register, ...rest] = String(schema).split('/')
	return generateUrl(
		`${API}/${encodeURIComponent(register)}/${encodeURIComponent(rest.join('/'))}`,
	)
}

export default {
	name: 'MappingLookupSettings',

	components: {
		NcButton,
		NcTextField,
	},

	data() {
		return {
			schemas: [],
			loading: true,
			busy: false,
			error: '',
			newSchema: '',
		}
	},

	/** @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the list: schemas, who added them and when.
		 *
		 * @return {Promise<void>} Resolves once the list is shown.
		 *
		 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(API))
				this.schemas = Array.isArray(data?.schemas) ? data.schemas : []
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The allowlist could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Add a schema; a refusal is shown as the backend says it.
		 *
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async add() {
			const schema = this.newSchema.trim()
			if (schema === '') {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(generateUrl(API), { schema })
				this.newSchema = ''
				showSuccess(
					t('integriq', '{schema} added to the allowlist.', { schema }),
				)
				await this.load()
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The schema was not added.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Take a schema off the list.
		 *
		 * @param {string} schema The schema.
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async remove(schema) {
			this.busy = true
			this.error = ''
			try {
				await axios.delete(removeUrl(schema))
				showSuccess(
					t('integriq', '{schema} removed from the allowlist.', {
						schema,
					}),
				)
				await this.load()
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The schema was not removed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.mapping-lookups__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.mapping-lookups__table th,
.mapping-lookups__table td {
	text-align: start;
	padding: 4px 8px;
}

.mapping-lookups__add {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 560px;
}

.mapping-lookups__add > :first-child {
	flex: 1 1 auto;
}
</style>
