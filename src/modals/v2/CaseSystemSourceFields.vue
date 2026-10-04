<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  CaseSystemSourceFields: the settings of a `case-system` source, shown by
  SourceFormFields when the source type is case-system.

  ZgwCaseSystem and CaseSystemOperations read everything from the source's
  `configuration` (design D2). Each control here writes one key under the
  exact name the backend reads: zakenSource, documentenSource, meetingZaaktype,
  bronorganisatie, auteur, confidentialAs, publicAs, kinds and mock. Keys this
  component does not know are kept, so nothing written elsewhere is lost.

  The component keeps its own draft and emits the whole configuration on every
  change, so several edits in a row never read a stale prop.
-->
<template>
	<div class="cn-case-system-fields">
		<h3 class="cn-case-system-fields__heading">
			{{ t('integriq', 'Case system settings') }}
		</h3>
		<p class="cn-case-system-fields__helper">
			{{
				t(
					'integriq',
					"This source answers a meeting app's case requests through the ZGW Zaken and Documenten APIs. Pick the two ZGW sources it uses.",
				)
			}}
		</p>

		<NcSelect
			inputId="cn-case-system-zaken-source"
			:inputLabel="t('integriq', 'Zaken API source')"
			:modelValue="optionFor(draft.zakenSource)"
			:options="sourceOptions"
			:loading="sourcesLoading"
			:clearable="true"
			:placeholder="t('integriq', 'Pick a source')"
			@update:modelValue="
				(option) => write('zakenSource', option?.id ?? '')
			" />

		<NcSelect
			inputId="cn-case-system-documenten-source"
			:inputLabel="t('integriq', 'Documenten API source')"
			:modelValue="optionFor(draft.documentenSource)"
			:options="sourceOptions"
			:loading="sourcesLoading"
			:clearable="true"
			:placeholder="t('integriq', 'Pick a source')"
			@update:modelValue="
				(option) => write('documentenSource', option?.id ?? '')
			" />
		<p
			v-if="!sourcesLoading && sourceOptions.length === 0"
			class="cn-case-system-fields__helper">
			{{
				t(
					'integriq',
					'No sources to pick from. Make a source for the Zaken API and one for the Documenten API first.',
				)
			}}
		</p>

		<NcTextField
			id="cn-case-system-meeting-zaaktype"
			:label="t('integriq', 'Meeting case type')"
			:modelValue="draft.meetingZaaktype ?? ''"
			type="url"
			:helperText="
				t('integriq', 'The address of the zaaktype a new meeting gets.')
			"
			@update:modelValue="(value) => write('meetingZaaktype', value)" />

		<NcTextField
			id="cn-case-system-bronorganisatie"
			:label="t('integriq', 'Your organisation\'s RSIN')"
			:modelValue="draft.bronorganisatie ?? ''"
			:helperText="
				t(
					'integriq',
					'Written as bronorganisatie on every new zaak and document.',
				)
			"
			@update:modelValue="(value) => write('bronorganisatie', value)" />

		<NcTextField
			id="cn-case-system-auteur"
			:label="t('integriq', 'Document author')"
			:modelValue="draft.auteur ?? ''"
			:helperText="t('integriq', 'Left empty, the source\'s name is used.')"
			@update:modelValue="(value) => write('auteur', value)" />

		<NcSelect
			inputId="cn-case-system-confidential-as"
			:inputLabel="t('integriq', 'Confidential documents are stored as')"
			:modelValue="levelFor(draft.confidentialAs, 'vertrouwelijk')"
			:options="levelOptions"
			:clearable="false"
			@update:modelValue="
				(option) => write('confidentialAs', option?.id ?? 'vertrouwelijk')
			" />

		<NcSelect
			inputId="cn-case-system-public-as"
			:inputLabel="t('integriq', 'Public documents are stored as')"
			:modelValue="levelFor(draft.publicAs, 'openbaar')"
			:options="levelOptions"
			:clearable="false"
			@update:modelValue="
				(option) => write('publicAs', option?.id ?? 'openbaar')
			" />

		<fieldset class="cn-case-system-fields__kinds">
			<legend class="cn-case-system-fields__label">
				{{ t('integriq', 'Document type per kind') }}
			</legend>
			<p class="cn-case-system-fields__helper">
				{{
					t(
						'integriq',
						'One row per kind the meeting app sends, for example besluitenlijst, with the address of its informatieobjecttype.',
					)
				}}
			</p>
			<div
				v-for="(row, index) in kindRows"
				:key="row.key"
				class="cn-case-system-fields__kind-row">
				<NcTextField
					:id="'cn-case-system-kind-name-' + row.key"
					:label="t('integriq', 'Kind')"
					:modelValue="row.kind"
					@update:modelValue="
						(value) => writeKind(index, 'kind', value)
					" />
				<NcTextField
					:id="'cn-case-system-kind-url-' + row.key"
					:label="t('integriq', 'Informatieobjecttype address')"
					:modelValue="row.url"
					type="url"
					@update:modelValue="(value) => writeKind(index, 'url', value)" />
				<NcButton
					data-testid="case-system-remove-kind"
					variant="tertiary"
					:aria-label="t('integriq', 'Remove this kind')"
					@click="removeKind(index)">
					{{ t('integriq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton
				data-testid="case-system-add-kind"
				variant="secondary"
				@click="addKind">
				{{ t('integriq', 'Add a kind') }}
			</NcButton>
		</fieldset>

		<NcCheckboxRadioSwitch
			:modelValue="draft.mock === true"
			type="switch"
			@update:modelValue="(value) => write('mock', value === true)">
			{{ t('integriq', 'Answer from test data') }}
		</NcCheckboxRadioSwitch>
		<p class="cn-case-system-fields__helper">
			{{
				t(
					'integriq',
					'Answers from built-in example cases instead of the case system. Nothing is stored.',
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The confidentiality levels (vertrouwelijkheidaanduiding) the ZGW Zaken 1.5
 * and Documenten 1.4 APIs accept. A value outside this list is refused by the
 * case system, so the picker offers nothing else.
 */
const ZGW_LEVELS = [
	'openbaar',
	'beperkt_openbaar',
	'intern',
	'zaakvertrouwelijk',
	'vertrouwelijk',
	'confidentieel',
	'geheim',
	'zeer_geheim',
]

let rowCounter = 0

/**
 * Turn the stored kinds object into editable rows.
 *
 * @param {object} kinds the stored kind to informatieobjecttype map
 * @return {object[]} the rows
 */
function rowsFrom(kinds) {
	const map =
		kinds && typeof kinds === 'object' && !Array.isArray(kinds) ? kinds : {}
	return Object.entries(map).map(([kind, url]) => ({
		key: ++rowCounter,
		kind,
		url: String(url ?? ''),
	}))
}

export default {
	name: 'CaseSystemSourceFields',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The source's configuration object. */
		configuration: { type: Object, default: () => ({}) },
	},

	emits: ['update:configuration'],

	data() {
		return {
			draft: { ...(this.configuration ?? {}) },
			kindRows: rowsFrom(this.configuration?.kinds),
			sourceOptions: [],
			sourcesLoading: false,
		}
	},

	computed: {
		/**
		 * The confidentiality levels as picker options.
		 *
		 * @return {object[]} the options
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
		 */
		levelOptions() {
			return ZGW_LEVELS.map((level) => ({ id: level, label: level }))
		},
	},

	watch: {
		/**
		 * Take over a configuration the parent swaps in, unless it is the one
		 * this component just emitted.
		 *
		 * @param {object} next the new configuration
		 * @return {void}
		 * @spec exclude reactive-state sync passthrough
		 */
		configuration(next) {
			if (next === this.lastEmitted) return
			this.draft = { ...(next ?? {}) }
			this.kindRows = rowsFrom(next?.kinds)
		},
	},

	/**
	 * Load the sources the two ZGW pickers offer.
	 *
	 * @return {void}
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	created() {
		this.lastEmitted = null
		this.fetchSources()
	},

	methods: {
		t,

		/**
		 * Load the instance's sources, leaving out case-system sources.
		 * A failed fetch leaves an empty list, never a broken editor.
		 *
		 * @return {Promise<void>} resolves once the list is loaded
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/integriq/source'),
					// `_limit`, not `limit`: an unprefixed param is a property filter.
					{ params: { _limit: 500 } },
				)
				const list = Array.isArray(response?.data?.results)
					? response.data.results
					: []
				this.sourceOptions = list
					.filter((item) => item?.type !== 'case-system')
					.map((item) => ({
						id: String(item['@self']?.id || item.id || item.uuid),
						label: item.name || item.title || String(item.id),
					}))
			} catch {
				this.sourceOptions = []
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * The picker option for a stored source id; an id this instance does
		 * not list is shown as itself rather than as empty.
		 *
		 * @param {string} id the stored source id
		 * @return {object|null} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		optionFor(id) {
			if (!id) return null
			return (
				this.sourceOptions.find((option) => option.id === String(id)) ?? {
					id: String(id),
					label: String(id),
				}
			)
		},

		/**
		 * The level option for a stored value, or the backend's default.
		 *
		 * @param {string} value the stored level
		 * @param {string} fallback the level the backend uses when unset
		 * @return {object} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		levelFor(value, fallback) {
			const level = value || fallback
			return { id: level, label: level }
		},

		/**
		 * Write one key and emit the whole configuration.
		 *
		 * @param {string} key the configuration key the backend reads
		 * @param {string|boolean|object} value the value
		 * @return {void}
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
		 */
		write(key, value) {
			this.draft = { ...this.draft, [key]: value }
			this.emitDraft()
		},

		/**
		 * Edit one kind row and write the kinds object.
		 *
		 * @param {number} index the row
		 * @param {string} field kind or url
		 * @param {string} value the value
		 * @return {void}
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
		 */
		writeKind(index, field, value) {
			const rows = [...this.kindRows]
			rows[index] = { ...rows[index], [field]: String(value ?? '') }
			this.kindRows = rows
			this.writeKinds()
		},

		/**
		 * Add an empty kind row. Nothing is written until it has a kind.
		 *
		 * @return {void}
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
		 */
		addKind() {
			this.kindRows = [
				...this.kindRows,
				{ key: ++rowCounter, kind: '', url: '' },
			]
		},

		/**
		 * Remove a kind row and write the kinds object.
		 *
		 * @param {number} index the row
		 * @return {void}
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
		 */
		removeKind(index) {
			this.kindRows = this.kindRows.filter((_row, i) => i !== index)
			this.writeKinds()
		},

		/**
		 * Build the kinds object ZgwCaseSystem reads (kind to address) from
		 * the rows that name a kind.
		 *
		 * @return {void}
		 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
		 */
		writeKinds() {
			const kinds = {}
			for (const row of this.kindRows) {
				const kind = row.kind.trim()
				if (kind !== '') {
					kinds[kind] = row.url.trim()
				}
			}
			this.write('kinds', kinds)
		},

		/**
		 * Emit a copy of the draft and remember it, so the watcher does not
		 * reset the rows when the parent hands it back.
		 *
		 * @return {void}
		 * @spec exclude emit passthrough
		 */
		emitDraft() {
			const configuration = { ...this.draft }
			this.lastEmitted = configuration
			this.$emit('update:configuration', configuration)
		},
	},
}
</script>

<style scoped>
.cn-case-system-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
}

.cn-case-system-fields__heading {
	margin: 0;
	font-size: 16px;
}

.cn-case-system-fields__label {
	font-weight: bold;
}

.cn-case-system-fields__helper {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-case-system-fields__kinds {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border: none;
	padding: 0;
	margin: 0;
}

.cn-case-system-fields__kind-row {
	display: grid;
	grid-template-columns: 1fr 2fr auto;
	gap: 8px;
	align-items: end;
}

@media (max-width: 600px) {
	.cn-case-system-fields__kind-row {
		grid-template-columns: 1fr;
	}
}
</style>
