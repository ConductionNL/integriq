<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  ExpressionSourceSettings: the environment allowlist on integriq's admin
  settings page (allowlisted-expression-sources task 3).

  An expression may read an environment variable only when an administrator
  has listed it here by its exact name. The list shows each name with who
  added it and when. It never shows a value, and not even whether the
  variable is set: that would be a map of what is worth asking for. The
  backend refuses anyone but an instance administrator twice
  (/api/admin/expression-sources), and records every change.

  @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
-->
<template>
	<div
		class="integriq-admin__section"
		data-testid="admin-expression-sources-section">
		<h3>{{ t('integriq', 'Environment variables an expression may read') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'An expression reads env:NAME only when NAME is on this list. Values are never shown or stored here, and every change is logged with your name.',
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
				v-if="keys.length === 0"
				class="integriq-admin__hint"
				data-testid="admin-expression-sources-empty">
				{{
					t(
						'integriq',
						'No environment variable is listed, so an expression can read none.',
					)
				}}
			</p>
			<table
				v-else
				class="expression-sources__table"
				data-testid="admin-expression-sources-list">
				<thead>
					<tr>
						<th scope="col">
							{{ t('integriq', 'Variable') }}
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
						v-for="entry in keys"
						:key="entry.key"
						data-testid="admin-expression-sources-row">
						<td>
							<code>{{ entry.key }}</code>
						</td>
						<td>{{ entry.addedBy }}</td>
						<td>{{ entry.addedAt }}</td>
						<td>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:aria-label="
									t('integriq', 'Remove {key}', { key: entry.key })
								"
								@click="remove(entry.key)">
								{{ t('integriq', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="expression-sources__add">
				<NcTextField
					v-model="newKey"
					data-testid="admin-expression-sources-key"
					:label="t('integriq', 'Variable name')"
					@keyup.enter="add" />
				<NcButton
					variant="primary"
					data-testid="admin-expression-sources-add"
					:disabled="busy || newKey.trim() === ''"
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

const API = '/apps/integriq/api/admin/expression-sources'

export default {
	name: 'ExpressionSourceSettings',

	components: {
		NcButton,
		NcTextField,
	},

	data() {
		return {
			keys: [],
			loading: true,
			busy: false,
			error: '',
			newKey: '',
		}
	},

	/** @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the list: names, who added them and when.
		 *
		 * @return {Promise<void>} Resolves once the list is shown.
		 *
		 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(API))
				this.keys = Array.isArray(data?.keys) ? data.keys : []
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The allowlist could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Add a variable by its exact name. A refusal (a name the backend will
		 * not list, or one already on it) is shown as the backend says it.
		 *
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
		 */
		async add() {
			const key = this.newKey.trim()
			if (key === '') {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(generateUrl(`${API}/env`), { key })
				this.newKey = ''
				showSuccess(t('integriq', '{key} added to the allowlist.', { key }))
				await this.load()
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The variable was not added.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Take a variable off the list.
		 *
		 * @param {string} key The variable name.
		 *
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
		 */
		async remove(key) {
			this.busy = true
			this.error = ''
			try {
				await axios.delete(
					generateUrl(`${API}/env/${encodeURIComponent(key)}`),
				)
				showSuccess(
					t('integriq', '{key} removed from the allowlist.', { key }),
				)
				await this.load()
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The variable was not removed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.expression-sources__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.expression-sources__table th,
.expression-sources__table td {
	text-align: start;
	padding: 4px 8px;
}

.expression-sources__add {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 560px;
}

.expression-sources__add > :first-child {
	flex: 1 1 auto;
}
</style>
