<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  LinkSourceDialog: "Add integration" on the App connections page
  (connection-registry, hydra umbrella design D9). Mounted once in ModalHost.

  The admin picks a declared connection that has no source, then an existing
  source or the source template of the connection, and saves. The backend links the
  source and probes it at once; the dialog shows the probe before it closes.
  It offers no field for a new connection key: a row nothing declared has
  nothing to check.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Add integration')"
		size="normal"
		data-testid="link-source-dialog"
		@update:open="onOpenChanged">
		<div class="link-source-dialog">
			<p>
				{{
					t(
						'integriq',
						'Link a source to a connection an app declared. The source is tested as soon as you save.',
					)
				}}
			</p>

			<NcSelect
				:modelValue="appFilter"
				:options="appOptions"
				:inputLabel="t('integriq', 'App')"
				:placeholder="t('integriq', 'All apps')"
				data-testid="link-source-app"
				@update:modelValue="onAppChanged" />

			<NcSelect
				:modelValue="selectedConnection"
				:options="connectionOptions"
				:loading="loading"
				:disabled="linked"
				:inputLabel="t('integriq', 'Connection')"
				:placeholder="t('integriq', 'Select a connection')"
				label="label"
				data-testid="link-source-connection"
				@update:modelValue="onConnectionChanged" />

			<NcNoteCard
				v-if="!loading && connectionOptions.length === 0"
				type="info">
				{{
					t('integriq', 'Every declared connection already has a source.')
				}}
			</NcNoteCard>

			<template v-if="selectedConnection && !linked">
				<NcCheckboxRadioSwitch
					v-if="selectedConnection.sourceTemplate"
					:modelValue="mode"
					value="template"
					name="link-source-mode"
					type="radio"
					data-testid="link-source-mode-template"
					@update:modelValue="onModeChanged">
					{{
						t(
							'integriq',
							'Create a source from the template {template}',
							{ template: selectedConnection.sourceTemplate },
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-if="selectedConnection.sourceTemplate"
					:modelValue="mode"
					value="existing"
					name="link-source-mode"
					type="radio"
					data-testid="link-source-mode-existing"
					@update:modelValue="onModeChanged">
					{{ t('integriq', 'Use an existing source') }}
				</NcCheckboxRadioSwitch>

				<NcSelect
					v-if="mode === 'existing'"
					:modelValue="selectedSource"
					:options="sourceOptions"
					:loading="loading"
					:inputLabel="t('integriq', 'Source')"
					:placeholder="t('integriq', 'Select a source')"
					label="label"
					data-testid="link-source-source"
					@update:modelValue="onSourceChanged" />
			</template>

			<NcNoteCard
				v-if="probe"
				:type="probe.status === 'ok' ? 'success' : 'error'"
				data-testid="link-source-probe">
				<p>
					{{
						probe.status === 'ok'
							? t('integriq', 'Linked. The test passed.')
							: t('integriq', 'Linked. The test failed.')
					}}
				</p>
				<p>{{ probe.message }}</p>
			</NcNoteCard>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<div class="link-source-dialog__actions">
				<NcButton variant="tertiary" @click="close">
					{{ linked ? t('integriq', 'Close') : t('integriq', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="!linked"
					variant="primary"
					:disabled="!canSave || saving"
					data-testid="link-source-save"
					@click="save">
					{{
						saving
							? t('integriq', 'Testing the source')
							: t('integriq', 'Save and test')
					}}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { rowId } from '../handlers/rowId.js'
import closableDialog from '../mixins/closableDialog.js'

/**
 * The integriq objects of one schema in OpenRegister.
 *
 * @param {string} schema The schema slug.
 * @return {Promise<Array<object>>} The objects.
 */
async function listObjects(schema) {
	const { data } = await axios.get(
		generateUrl(`/apps/openregister/api/objects/integriq/${schema}`),
		{ params: { _limit: 500 } },
	)
	return data?.results || []
}

/**
 * An app connection as a select option, with its source template.
 *
 * @param {object} row One app_connection object.
 * @return {object} The option.
 */
function connectionOption(row) {
	return {
		id: String(rowId(row)),
		app: row.app,
		label: `${row.title} (${row.app})`,
		sourceTemplate: row.declaration?.sourceTemplate || '',
	}
}

/**
 * A source as a select option.
 *
 * @param {object} row One source object.
 * @return {{id: string, label: string}} The option.
 */
function sourceOption(row) {
	return {
		id: String(rowId(row)),
		label: row.name || row.title || String(rowId(row)),
	}
}

/**
 * Why the connections and sources could not be loaded.
 *
 * @param {object} err The failed request.
 * @return {string} The translated text.
 */
function loadFailedText(err) {
	return t('integriq', 'Could not load connections and sources: {error}', {
		error: err?.response?.data?.error || err?.message || '',
	})
}

/**
 * Why the source could not be linked.
 *
 * @param {object} err The failed request.
 * @return {string} The text to show.
 */
function linkFailedText(err) {
	return (
		err?.response?.data?.error
		|| err?.message
		|| t('integriq', 'The source could not be linked.')
	)
}

export default {
	name: 'LinkSourceDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	mixins: [closableDialog],

	props: {
		open: { type: Boolean, default: false },
		/** App id to pre-filter by, from the page's `?app=` query. */
		app: { type: String, default: '' },
	},

	emits: ['close', 'linked'],

	data() {
		return {
			connections: [],
			sourceOptions: [],
			appFilter: null,
			selectedConnection: null,
			selectedSource: null,
			mode: 'existing',
			loading: false,
			saving: false,
			linked: false,
			probe: null,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * Apps that have at least one connection without a source.
		 *
		 * @return {string[]}
		 * @spec openspec/specs/connection-registry/spec.md#scenario-the-link-query-opens-the-dialog-pre-filtered
		 */
		appOptions() {
			return [
				...new Set(this.connections.map((connection) => connection.app)),
			].sort()
		},

		/**
		 * Declared connections without a source, narrowed by the app filter.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/connection-registry/spec.md#scenario-add-integration-opens-the-dialog
		 */
		connectionOptions() {
			return this.connections.filter(
				(connection) => !this.appFilter || connection.app === this.appFilter,
			)
		},

		/**
		 * Whether the choices are complete enough to save.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		canSave() {
			if (!this.selectedConnection) {
				return false
			}
			return this.mode === 'template' || Boolean(this.selectedSource)
		},
	},

	watch: {
		/**
		 * Reset and reload on every open, so a stale choice from a previous
		 * open can never be saved without the admin making it again.
		 *
		 * @param {boolean} isOpen Whether the dialog is being shown.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#scenario-add-integration-opens-the-dialog
		 */
		open(isOpen) {
			if (!isOpen) {
				return
			}
			this.appFilter = this.app || null
			this.selectedConnection = null
			this.selectedSource = null
			this.mode = 'existing'
			this.linked = false
			this.probe = null
			this.errorMessage = ''
			this.load()
		},
	},

	methods: {
		/**
		 * Show the connections that have no source yet, and the sources.
		 *
		 * @param {Array<Array<object>>} lists The connections and the sources.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		showLists([connections, sources]) {
			this.connections = connections
				.filter((row) => !row.source)
				.map(connectionOption)
			this.sourceOptions = sources.map(sourceOption)
		},

		/**
		 * Load unlinked connections and every source, through OpenRegister's
		 * object API. `_limit` is prefixed: an unprefixed parameter is a
		 * property filter there.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		async load() {
			this.loading = true
			try {
				this.showLists(
					await Promise.all([
						listObjects('app_connection'),
						listObjects('source'),
					]),
				)
			} catch (err) {
				this.errorMessage = loadFailedText(err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string|null} app The chosen app.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#scenario-the-link-query-opens-the-dialog-pre-filtered
		 */
		onAppChanged(app) {
			this.appFilter = app || null
			if (
				this.selectedConnection
				&& this.appFilter
				&& this.selectedConnection.app !== this.appFilter
			) {
				this.selectedConnection = null
			}
		},

		/**
		 * Choosing a connection with a template offers the template first.
		 *
		 * @param {object|null} connection The chosen connection.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		onConnectionChanged(connection) {
			this.selectedConnection = connection || null
			this.mode = connection?.sourceTemplate ? 'template' : 'existing'
		},

		/**
		 * @param {string} mode `template` or `existing`.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		onModeChanged(mode) {
			this.mode = mode
		},

		/**
		 * @param {object|null} source The chosen source.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		onSourceChanged(source) {
			this.selectedSource = source || null
		},

		/**
		 * What the link request sends: the template, or the picked source.
		 *
		 * @return {object} The request body.
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		linkBody() {
			return this.mode === 'template'
				? { fromTemplate: true }
				: { source: this.selectedSource.id }
		},

		/**
		 * POST the link of the chosen connection.
		 *
		 * @param {object} body The request body.
		 * @return {Promise<object>} What the server answered.
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		async postLink(body) {
			const { data } = await axios.post(
				generateUrl(
					`/apps/integriq/api/connections/${this.selectedConnection.id}/link`,
				),
				body,
			)
			return data
		},

		/**
		 * Show the probe of the linked connection and tell the parent.
		 *
		 * @param {object|null} data What the server answered.
		 * @return {void}
		 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
		 */
		showLinked(data) {
			this.probe = data?.probe || null
			this.linked = true
			this.$emit('linked', data?.connection || null)
		},

		/**
		 * Link, probe and show the result. The dialog stays open on the result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/connection-registry/spec.md#scenario-linking-a-source-probes-it-straight-away
		 */
		async save() {
			this.saving = true
			this.errorMessage = ''
			const body = this.linkBody()
			try {
				this.showLinked(await this.postLink(body))
			} catch (err) {
				this.errorMessage = linkFailedText(err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.link-source-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.link-source-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
