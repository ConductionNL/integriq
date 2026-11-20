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
	<AllowlistEditor
		v-model="newKey"
		testid="admin-expression-sources"
		field="key"
		:entries="keys"
		:title="t('integriq', 'Environment variables an expression may read')"
		:busy="busy"
		:hint="
			t(
				'integriq',
				'An expression reads env:NAME only when NAME is on this list. Values are never shown or stored here, and every change is logged with your name.',
			)
		"
		:loading="loading"
		:emptyText="
			t(
				'integriq',
				'No environment variable is listed, so an expression can read none.',
			)
		"
		:error="error"
		:column="t('integriq', 'Variable')"
		:inputLabel="t('integriq', 'Variable name')"
		:removeLabel="(key) => t('integriq', 'Remove {key}', { key })"
		@add="add"
		@remove="remove" />
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import AllowlistEditor from '../../components/admin/AllowlistEditor.vue'
import allowlistEditor from '../../mixins/allowlistEditor.js'

const API = '/apps/integriq/api/admin/expression-sources'

export default {
	name: 'ExpressionSourceSettings',

	components: { AllowlistEditor },

	mixins: [allowlistEditor],

	data() {
		return {
			keys: [],
			newKey: '',
		}
	},

	methods: {
		/**
		 * Load the list: names, who added them and when.
		 *
		 * @return {Promise<void>} Resolves once the list is shown.
		 *
		 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
		 */
		async load() {
			const keys = await this.fetchList(API, 'keys')
			if (keys !== null) {
				this.keys = keys
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
			await this.changeList(
				async () => {
					await axios.post(generateUrl(`${API}/env`), { key })
					this.newKey = ''
				},
				t('integriq', '{key} added to the allowlist.', { key }),
				t('integriq', 'The variable was not added.'),
			)
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
			await this.changeList(
				() => axios.delete(generateUrl(`${API}/env/${encodeURIComponent(key)}`)),
				t('integriq', '{key} removed from the allowlist.', { key }),
				t('integriq', 'The variable was not removed.'),
			)
		},
	},
}
</script>
