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

  @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
-->
<template>
	<AllowlistEditor
		v-model="newSchema"
		testid="admin-mapping-lookups"
		field="schema"
		:entries="schemas"
		:title="t('integriq', 'Register schemas a mapping may look up in')"
		:busy="busy"
		:hint="
			t(
				'integriq',
				'A mapping can translate a code with lookup() only in a schema on this list. Every change is logged with your name.',
			)
		"
		:loading="loading"
		:emptyText="t('integriq', 'No schema is listed, so a mapping can look up in none.')"
		:error="error"
		:column="t('integriq', 'Schema')"
		:inputLabel="t('integriq', 'Schema (register/schema)')"
		:removeLabel="(schema) => t('integriq', 'Remove {schema}', { schema })"
		@add="add"
		@remove="remove" />
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import AllowlistEditor from '../../components/admin/AllowlistEditor.vue'
import allowlistEditor from '../../mixins/allowlistEditor.js'

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

	components: { AllowlistEditor },

	mixins: [allowlistEditor],

	data() {
		return {
			schemas: [],
			newSchema: '',
		}
	},

	methods: {
		/**
		 * Load the list: schemas, who added them and when.
		 *
		 * @return {Promise<void>} Resolves once the list is shown.
		 *
		 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async load() {
			const schemas = await this.fetchList(API, 'schemas')
			if (schemas !== null) {
				this.schemas = schemas
			}
		},

		/**
		 * Add a schema; a refusal is shown as the backend says it.
		 *
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async add() {
			const schema = this.newSchema.trim()
			if (schema === '') {
				return
			}
			await this.changeList(
				async () => {
					await axios.post(generateUrl(API), { schema })
					this.newSchema = ''
				},
				t('integriq', '{schema} added to the allowlist.', { schema }),
				t('integriq', 'The schema was not added.'),
			)
		},

		/**
		 * Take a schema off the list.
		 *
		 * @param {string} schema The schema.
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async remove(schema) {
			await this.changeList(
				() => axios.delete(removeUrl(schema)),
				t('integriq', '{schema} removed from the allowlist.', { schema }),
				t('integriq', 'The schema was not removed.'),
			)
		},
	},
}
</script>
