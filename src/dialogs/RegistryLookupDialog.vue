<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  RegistryLookupDialog: "Look up in a base registry" on the Sources page.
  Mounted once in ModalHost.

  The property-source engine (BAG, BRP, KvK and the list-shaped providers)
  had four routes and no screen. Here an administrator picks a registry,
  types a search, picks a suggestion and sees the value the registry holds,
  with where it came from, when it was read and whether the registry
  answered. A suggestion is never shown as the answer: it is resolved by its
  identifier first. "Read again now" skips the cache, and a list-shaped
  registry can be resynced, keeping the previous list when that fails.

  @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Look up in a base registry')"
		size="normal"
		data-testid="registry-lookup-dialog"
		@update:open="onOpenChanged">
		<div class="registry-lookup">
			<p class="registry-lookup__muted">
				{{
					t(
						'integriq',
						'Integriq reads the value from the registry when a field needs it and keeps no copy. Try a lookup here.',
					)
				}}
			</p>

			<NcSelect
				v-model="selectedProvider"
				data-testid="registry-lookup-provider"
				:inputLabel="t('integriq', 'Registry')"
				:options="providerOptions"
				:loading="loadingProviders"
				@update:modelValue="onPickProvider" />

			<template v-if="selectedProvider">
				<div class="registry-lookup__row">
					<NcTextField
						v-model="query"
						data-testid="registry-lookup-query"
						:label="t('integriq', 'Search')"
						@keyup.enter="runSuggest" />
					<NcButton
						data-testid="registry-lookup-search"
						:disabled="query.trim() === '' || busy"
						@click="runSuggest">
						{{ t('integriq', 'Search') }}
					</NcButton>
				</div>

				<ul
					v-if="suggestions.length > 0"
					class="registry-lookup__suggestions"
					data-testid="registry-lookup-suggestions">
					<li
						v-for="suggestion in suggestions"
						:key="suggestion.identifier">
						<NcButton
							variant="tertiary"
							:disabled="busy"
							@click="resolve(suggestion.identifier, false)">
							{{ suggestion.label }}
						</NcButton>
					</li>
				</ul>
				<p v-else-if="searched" class="registry-lookup__muted">
					{{
						t('integriq', 'The registry found nothing for this search.')
					}}
				</p>

				<NcNoteCard
					v-if="error"
					type="error"
					data-testid="registry-lookup-error">
					{{ error }}
				</NcNoteCard>

				<section
					v-if="resolved"
					class="registry-lookup__resolved"
					data-testid="registry-lookup-resolved">
					<NcNoteCard
						:type="stateNoteType"
						data-testid="registry-lookup-state">
						{{ state.text }}
					</NcNoteCard>
					<dl class="registry-lookup__value">
						<template v-for="row in rows" :key="row.key">
							<dt v-if="row.key">
								{{ row.key }}
							</dt>
							<dd>{{ row.text }}</dd>
						</template>
					</dl>
					<p class="registry-lookup__muted">
						{{
							t(
								'integriq',
								'Registry key {identifier} from {provider}.',
								{
									identifier:
										resolved.provenance?.sourceIdentifier ?? '',
									provider: resolved.provenance?.provider ?? '',
								},
							)
						}}
					</p>
					<NcButton
						data-testid="registry-lookup-fresh"
						:disabled="busy"
						@click="
							resolve(resolved.provenance?.sourceIdentifier, true)
						">
						{{ t('integriq', 'Read again now') }}
					</NcButton>
				</section>

				<section v-if="isListShaped" class="registry-lookup__resync">
					<NcButton
						data-testid="registry-lookup-resync"
						:disabled="busy"
						@click="runResync">
						{{ t('integriq', 'Resync the list') }}
					</NcButton>
					<NcNoteCard
						v-if="resyncReport"
						:type="
							resyncReport.succeeded === false ? 'error' : 'success'
						"
						data-testid="registry-lookup-resync-report">
						{{ resyncText }}
					</NcNoteCard>
				</section>
			</template>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ t('integriq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { listOf, provenanceState, valueRows } from './registryLookup.js'

const API = '/apps/integriq/api/property-sources'

export default {
	name: 'RegistryLookupDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Whether the dialog is shown. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		return {
			providers: [],
			loadingProviders: false,
			selectedProvider: null,
			query: '',
			suggestions: [],
			searched: false,
			resolved: null,
			resyncReport: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-describe-says-what-the-provider-keys-on */
		providerOptions() {
			return this.providers.map((provider) => ({
				id: provider.id,
				label: provider.label || provider.id,
				provider,
			}))
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007 */
		isListShaped() {
			return this.selectedProvider?.provider?.listShaped === true
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003 */
		state() {
			return provenanceState(this.resolved?.provenance, t)
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-an-unreachable-source-degrades-to-a-labelled-last-value-req-rfs-005 */
		stateNoteType() {
			if (this.state.state === 'unreachable') {
				return 'warning'
			}
			return this.state.state === 'live' ? 'success' : 'info'
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001 */
		rows() {
			return valueRows(this.resolved?.value)
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007 */
		resyncText() {
			const report = this.resyncReport || {}
			if (report.succeeded === false) {
				return t(
					'integriq',
					'The resync failed, so the previous list stays in use: {message}',
					{
						message: report.message || report.error || '',
					},
				)
			}
			return t('integriq', 'Resynced. {count} entries changed.', {
				count: report.changed ?? 0,
			})
		},
	},

	watch: {
		/**
		 * Load the registries each time the dialog opens.
		 *
		 * @param {boolean} isOpen Whether the dialog is open.
		 *
		 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-describe-says-what-the-provider-keys-on
		 */
		open(isOpen) {
			if (isOpen) {
				this.fetchProviders()
			}
		},
	},

	/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-describe-says-what-the-provider-keys-on */
	mounted() {
		if (this.open) {
			this.fetchProviders()
		}
	},

	methods: {
		t,

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-describe-says-what-the-provider-keys-on */
		async fetchProviders() {
			this.loadingProviders = true
			try {
				const response = await axios.get(generateUrl(API))
				this.providers = listOf(response.data)
			} catch {
				this.providers = []
			} finally {
				this.loadingProviders = false
			}
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001 */
		onPickProvider() {
			this.query = ''
			this.suggestions = []
			this.searched = false
			this.resolved = null
			this.resyncReport = null
			this.error = ''
		},

		/**
		 * Ask the registry for suggestions. A suggestion is not an answer;
		 * picking one resolves it by its identifier.
		 *
		 * @return {Promise<void>} Resolves once the suggestions are shown.
		 *
		 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-an-applicant-types-an-address
		 */
		async runSuggest() {
			if (!this.selectedProvider || this.query.trim() === '') {
				return
			}
			this.busy = true
			this.error = ''
			this.resolved = null
			try {
				const response = await axios.get(
					generateUrl(`${API}/${this.selectedProvider.id}/suggest`),
					{ params: { q: this.query.trim() } },
				)
				this.suggestions = listOf(response.data)
			} catch (error) {
				this.suggestions = []
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The search failed.')
			} finally {
				this.searched = true
				this.busy = false
			}
		},

		/**
		 * Read the value the registry holds for an identifier.
		 *
		 * @param {string} identifier The registry key.
		 * @param {boolean} fresh Skip the cache and read the registry now.
		 *
		 * @return {Promise<void>} Resolves once the value is shown.
		 *
		 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-a-caller-demands-a-fresh-read
		 */
		async resolve(identifier, fresh) {
			if (!identifier) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const params = { identifier }
				if (fresh) {
					params.fresh = true
				}
				const response = await axios.get(
					generateUrl(`${API}/${this.selectedProvider.id}/resolve`),
					{ params },
				)
				this.resolved = response.data
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The lookup failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Fetch a list-shaped registry again, on demand.
		 *
		 * @return {Promise<void>} Resolves once the report is shown.
		 *
		 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-an-administrator-resyncs-the-classification-plan
		 */
		async runResync() {
			this.busy = true
			this.error = ''
			try {
				const response = await axios.post(
					generateUrl(`${API}/${this.selectedProvider.id}/resync`),
				)
				this.resyncReport = response.data
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The resync failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {boolean} isOpen The dialog's new open state.
		 *
		 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
		 */
		onOpenChanged(isOpen) {
			if (!isOpen) {
				this.close()
			}
		},

		/** @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.registry-lookup {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block-end: 12px;
}

.registry-lookup__muted {
	color: var(--color-text-maxcontrast);
}

.registry-lookup__row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
}

.registry-lookup__row > :first-child {
	flex: 1 1 auto;
}

.registry-lookup__suggestions {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.registry-lookup__value {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
}

.registry-lookup__value dt {
	font-weight: bold;
}

.registry-lookup__resolved,
.registry-lookup__resync {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
