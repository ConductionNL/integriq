<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  DatabaseSourcePanel: the declared statements of a database source, a test
  run of a read statement, and the tables and columns of its database.

  Mounted as a body widget on SourceDetail and reads the loaded source off
  `cnSectionContext`, like DocumentGenerationSourcePanel. It renders nothing
  for any other kind of source. A test run shows the first page of rows and
  how long the statement took; a write statement is never run from here. The
  panel says whether the source declares write statements, so an
  administrator can tell whether a read-only account is enough.

  @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
-->
<template>
	<section
		v-if="isDatabase"
		class="dbSource"
		data-testid="database-source-panel">
		<h3>{{ t('integriq', 'Database connection') }}</h3>

		<p data-testid="database-source-connection">
			{{ connectionText }}
		</p>
		<p
			:class="{ 'dbSource__warning': hasWriteStatements }"
			data-testid="database-source-account-advice">
			{{ hasWriteStatements
				? t('integriq', 'This source declares write statements. Its account needs only the rights those statements use.')
				: t('integriq', 'This source only reads. Connect with a read-only account.') }}
		</p>

		<h4>{{ t('integriq', 'Declared statements') }}</h4>
		<p v-if="statements.length === 0" data-testid="database-source-no-statements">
			{{ t('integriq', 'No statements are declared. Integriq runs only the statements you declare on the source.') }}
		</p>
		<ul v-else class="dbSource__statements">
			<li
				v-for="statement in statements"
				:key="statement.name"
				class="dbSource__statement"
				:data-statement="statement.name">
				<div class="dbSource__statementHead">
					<strong>{{ statement.name }}</strong>
					<span class="dbSource__kind">
						{{ statement.kind === 'write' ? t('integriq', 'Write statement') : t('integriq', 'Read statement') }}
					</span>
				</div>
				<code class="dbSource__sql">{{ statement.sql }}</code>
				<template v-if="statement.kind !== 'write'">
					<div
						v-for="parameter in parametersOf(statement)"
						:key="parameter"
						class="dbSource__parameter">
						<NcTextField
							:modelValue="valueOf(statement.name, parameter)"
							:label="parameter"
							:data-testid="`database-source-parameter-${parameter}`"
							@update:modelValue="setValue(statement.name, parameter, $event)" />
					</div>
					<NcButton
						variant="secondary"
						:disabled="running === statement.name || !objectId"
						:data-testid="`database-source-test-${statement.name}`"
						@click="runTest(statement.name)">
						{{ t('integriq', 'Test the statement') }}
					</NcButton>
				</template>

				<div
					v-if="results[statement.name]"
					class="dbSource__result"
					data-testid="database-source-test-result">
					<p v-if="results[statement.name].error" class="dbSource__error" role="alert">
						{{ results[statement.name].error }}
					</p>
					<template v-else>
						<p data-testid="database-source-test-summary">
							{{ summaryOf(results[statement.name]) }}
						</p>
						<div v-if="results[statement.name].rows.length > 0" class="dbSource__tableWrap">
							<table class="dbSource__table" data-testid="database-source-test-rows">
								<thead>
									<tr>
										<th v-for="column in columnsOf(results[statement.name].rows)" :key="column" scope="col">
											{{ column }}
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(row, index) in results[statement.name].rows" :key="index">
										<td v-for="column in columnsOf(results[statement.name].rows)" :key="column">
											{{ cell(row[column]) }}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</template>
				</div>
			</li>
		</ul>

		<h4>{{ t('integriq', 'Tables and columns') }}</h4>
		<NcButton
			variant="secondary"
			:disabled="discovering || !objectId"
			data-testid="database-source-discover"
			@click="discover">
			{{ t('integriq', 'Show tables and columns') }}
		</NcButton>
		<p v-if="schemaError" class="dbSource__error" role="alert">
			{{ schemaError }}
		</p>
		<p v-else-if="tables !== null && tables.length === 0" data-testid="database-source-no-tables">
			{{ t('integriq', 'The database has no tables or views this account can see.') }}
		</p>
		<ul v-else-if="tables !== null" class="dbSource__tables" data-testid="database-source-tables">
			<li v-for="table in tables" :key="table.name" :data-table="table.name">
				<strong>{{ table.name }}</strong>
				<span class="dbSource__kind">
					{{ table.kind === 'view' ? t('integriq', 'Database view') : t('integriq', 'Table') }}
				</span>
				<span class="dbSource__columns">
					{{ (table.columns || []).map((column) => `${column.name} (${column.type})`).join(', ') }}
				</span>
			</li>
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'

/** The source `type` DatabaseAdapter::SOURCE_TYPE names. */
export const DATABASE_TYPE = 'database'

/** The placeholders a paged read binds itself. */
const RESERVED = ['after', 'limit']

export default {
	name: 'DatabaseSourcePanel',

	components: { NcButton, NcTextField },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			values: {},
			results: {},
			running: '',
			discovering: false,
			tables: null,
			schemaError: '',
		}
	},

	computed: {
		/**
		 * The loaded source object.
		 *
		 * @return {object}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		source() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return (value && value.object) || {}
		},

		/**
		 * The source's id for the endpoint URLs.
		 *
		 * @return {string}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		objectId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return String(
				(value && value.objectId)
					|| this.source['@self']?.id
					|| this.source.uuid
					|| this.source.id
					|| '',
			)
		},

		/**
		 * Whether this is a database source.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		isDatabase() {
			return this.source.type === DATABASE_TYPE
		},

		/**
		 * The declared statements.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		statements() {
			return Array.isArray(this.source.statements)
				? this.source.statements.filter((statement) => statement && typeof statement.name === 'string')
				: []
		},

		/**
		 * Whether any declared statement writes.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		hasWriteStatements() {
			return this.statements.some((statement) => statement.kind === 'write')
		},

		/**
		 * Where and as whom the source connects.
		 *
		 * @return {string}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		connectionText() {
			const database = this.source.database || {}
			if (!database.host || !database.dbname) {
				return t('integriq', 'No database host and name are set yet.')
			}
			return t('integriq', 'Database {dbname} on {host} as {user}', {
				dbname: database.dbname,
				host: database.port ? `${database.host}:${database.port}` : database.host,
				user: database.user || t('integriq', 'no account'),
			})
		},
	},

	methods: {
		t,

		/**
		 * The parameters a test run asks values for.
		 *
		 * @param {object} statement The statement.
		 * @return {Array<string>}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		parametersOf(statement) {
			const declared = Array.isArray(statement.parameters) ? statement.parameters : []
			return declared
				.map((parameter) => (typeof parameter === 'string' ? parameter : parameter?.name))
				.filter((name) => typeof name === 'string' && name !== '' && !RESERVED.includes(name))
		},

		/**
		 * The value typed for one parameter.
		 *
		 * @param {string} statement The statement's name.
		 * @param {string} parameter The parameter.
		 * @return {string}
		 * @spec exclude form plumbing
		 */
		valueOf(statement, parameter) {
			return (this.values[statement] || {})[parameter] || ''
		},

		/**
		 * Keep the value typed for one parameter.
		 *
		 * @param {string} statement The statement's name.
		 * @param {string} parameter The parameter.
		 * @param {string} value The value.
		 * @return {void}
		 * @spec exclude form plumbing
		 */
		setValue(statement, parameter, value) {
			this.values = { ...this.values, [statement]: { ...(this.values[statement] || {}), [parameter]: value } }
		},

		/**
		 * Run a read statement once and show its first page.
		 *
		 * @param {string} name The statement's name.
		 * @return {Promise<void>}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#scenario-an-administrator-connects-a-back-office-database
		 */
		async runTest(name) {
			this.running = name
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/integriq/api/sources/${encodeURIComponent(this.objectId)}/database/statements/${encodeURIComponent(name)}/test`,
					),
					{ parameters: this.values[name] || {} },
				)
				this.results = { ...this.results, [name]: { rows: Array.isArray(data?.rows) ? data.rows : [], more: data?.more === true, durationMs: Number(data?.durationMs ?? 0), error: '' } }
			} catch (e) {
				this.results = {
					...this.results,
					[name]: { rows: [], more: false, durationMs: 0, error: e?.response?.data?.error || t('integriq', 'The statement could not be run.') },
				}
			} finally {
				this.running = ''
			}
		},

		/**
		 * List the tables and views with their columns.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
		 */
		async discover() {
			this.discovering = true
			this.schemaError = ''
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/integriq/api/sources/${encodeURIComponent(this.objectId)}/database/schema`),
				)
				this.tables = Array.isArray(data?.tables) ? data.tables : []
			} catch (e) {
				this.tables = null
				this.schemaError = e?.response?.data?.error || t('integriq', 'The tables and columns could not be read.')
			} finally {
				this.discovering = false
			}
		},

		/**
		 * The line above a test run's rows: how many, whether more follow, how long.
		 *
		 * @param {object} result The test run's result.
		 * @return {string}
		 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#scenario-an-administrator-connects-a-back-office-database
		 */
		summaryOf(result) {
			const values = { count: result.rows.length, duration: result.durationMs }
			return result.more
				? t('integriq', 'Rows on the first page: {count}, in {duration} ms. More rows follow.', values)
				: t('integriq', 'Rows on the first page: {count}, in {duration} ms.', values)
		},

		/**
		 * The columns of the rows, in the order the first row has them.
		 *
		 * @param {Array<object>} rows The rows.
		 * @return {Array<string>}
		 * @spec exclude display helper
		 */
		columnsOf(rows) {
			return rows.length > 0 && rows[0] && typeof rows[0] === 'object' ? Object.keys(rows[0]) : []
		},

		/**
		 * One value as text.
		 *
		 * @param {string|number|boolean|object|null} value The value.
		 * @return {string}
		 * @spec exclude display helper
		 */
		cell(value) {
			if (value === null || value === undefined) {
				return ''
			}
			return typeof value === 'object' ? JSON.stringify(value) : String(value)
		},
	},
}
</script>

<style scoped>
.dbSource {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.dbSource__warning,
.dbSource__error {
	color: var(--color-error-text);
}

.dbSource__statements,
.dbSource__tables {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: 0;
	list-style: none;
}

.dbSource__statement {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.dbSource__statementHead {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: baseline;
}

.dbSource__kind,
.dbSource__columns {
	color: var(--color-text-maxcontrast);
}

.dbSource__sql {
	white-space: pre-wrap;
	word-break: break-word;
}

.dbSource__tableWrap {
	overflow-x: auto;
}

.dbSource__table {
	border-collapse: collapse;
}

.dbSource__table th,
.dbSource__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}
</style>
