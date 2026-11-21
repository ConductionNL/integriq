<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  CtiSourceFields: the settings of a `cti` source (a telephone exchange that
  posts call events), shown by SourceFormFields when the source type is cti.

  CtiSourceResolver, WebhookCtiProvider, LogCtiProvider and
  ConfiguredCallerSearch read everything from the source's `configuration`.
  Each control here writes one key under the exact name the backend reads:
  provider, sharedSecret, signatureSecret, eventsPath, fieldMapping,
  kindMapping and callerLookupSource. Keys this component does not know are
  kept, so nothing written elsewhere is lost.

  The component keeps its own draft and emits the whole configuration on every
  change, so several edits in a row never read a stale prop.

  @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
-->
<template>
	<div class="cn-cti-fields">
		<h3 class="cn-cti-fields__heading">
			{{ t('integriq', 'Telephony settings') }}
		</h3>
		<p class="cn-cti-fields__helper">
			{{
				t(
					'integriq',
					'This source receives call events from a phone system and hands each call, with the caller it found, to the app that shows it.',
				)
			}}
		</p>

		<NcSelect
			inputId="cn-cti-provider"
			:inputLabel="t('integriq', 'Telephony provider')"
			:modelValue="selectedProvider"
			:options="providerOptions"
			:clearable="true"
			:placeholder="t('integriq', 'Select how the phone system delivers its events')"
			@update:modelValue="(option) => write('provider', option?.id ?? '')" />
		<p
			v-if="unknownProvider"
			class="cn-cti-fields__note">
			{{
				t(
					'integriq',
					'This provider is not installed on this instance, so its calls are refused. Pick one of the providers above.',
				)
			}}
		</p>

		<template v-if="draft.provider === 'webhook'">
			<NcTextField
				:label="t('integriq', 'Signature secret')"
				type="password"
				:modelValue="stringOf(draft.signatureSecret)"
				@update:modelValue="write('signatureSecret', $event)" />
			<p class="cn-cti-fields__helper">
				{{
					t(
						'integriq',
						'The secret the phone system signs each request body with. Preferred over a shared secret, because a signature covers the body as well as the caller.',
					)
				}}
			</p>
		</template>

		<template v-if="draft.provider === 'webhook' || draft.provider === 'log'">
			<NcTextField
				:label="t('integriq', 'Shared secret')"
				type="password"
				:modelValue="stringOf(draft.sharedSecret)"
				@update:modelValue="write('sharedSecret', $event)" />
			<p class="cn-cti-fields__helper">
				{{
					t(
						'integriq',
						'The value the phone system must send in the X-Api-Key header. A source with neither secret refuses every call.',
					)
				}}
			</p>
			<p
				v-if="!hasSecret"
				class="cn-cti-fields__note">
				{{
					t(
						'integriq',
						'No secret is set yet, so every call to this source is refused.',
					)
				}}
			</p>
		</template>

		<template v-if="draft.provider === 'webhook'">
			<NcTextField
				:label="t('integriq', 'Events path')"
				:modelValue="stringOf(draft.eventsPath)"
				@update:modelValue="write('eventsPath', $event)" />
			<p class="cn-cti-fields__helper">
				{{
					t(
						'integriq',
						'Dotted path to the list of events in the request. Leave it empty when the request is one event.',
					)
				}}
			</p>

			<fieldset class="cn-cti-fields__group">
				<legend class="cn-cti-fields__label">
					{{ t('integriq', 'Field mapping') }}
				</legend>
				<p class="cn-cti-fields__helper">
					{{
						t(
							'integriq',
							'Where each call field is in the request, as a dotted path. Leave a field empty when the phone system uses the same name.',
						)
					}}
				</p>
				<NcTextField
					v-for="field in callFields"
					:key="'cti-field-' + field.key"
					:label="field.label"
					:modelValue="stringOf(draft.fieldMapping?.[field.key])"
					@update:modelValue="writeMapping(field.key, $event)" />
			</fieldset>

			<fieldset class="cn-cti-fields__group">
				<legend class="cn-cti-fields__label">
					{{ t('integriq', 'Call kinds') }}
				</legend>
				<p class="cn-cti-fields__helper">
					{{
						t(
							'integriq',
							'The word the phone system uses for each kind of event. Separate several words with a comma. An event whose word matches no kind is dropped.',
						)
					}}
				</p>
				<NcTextField
					v-for="kind in callKinds"
					:key="'cti-kind-' + kind.id"
					:label="kind.label"
					:modelValue="vendorWordsFor(kind.id)"
					@update:modelValue="writeKind(kind.id, $event)" />
			</fieldset>
		</template>

		<NcSelect
			inputId="cn-cti-caller-lookup-source"
			:inputLabel="t('integriq', 'Caller lookup source')"
			:modelValue="optionFor(draft.callerLookupSource)"
			:options="lookupSourceOptions"
			:loading="sourcesLoading"
			:clearable="true"
			:placeholder="t('integriq', 'Pick a KISS source')"
			@update:modelValue="(option) => write('callerLookupSource', option?.id ?? '')" />
		<p class="cn-cti-fields__helper">
			{{
				t(
					'integriq',
					'The KISS source whose contact register is searched for the calling number. Without one, every caller is shown as unknown.',
				)
			}}
		</p>
		<p
			v-if="!sourcesLoading && lookupSourceOptions.length === 0"
			class="cn-cti-fields__note">
			{{
				t(
					'integriq',
					'No KISS source to pick from. Make a KISS source on the REST provider first.',
				)
			}}
		</p>

		<template v-if="draft.provider">
			<span class="cn-cti-fields__label">
				{{ t('integriq', 'Events URL') }}
			</span>
			<code
				v-if="eventsUrl"
				class="cn-cti-fields__url">{{ eventsUrl }}</code>
			<p class="cn-cti-fields__helper">
				{{
					eventsUrl
						? t('integriq', 'Enter this URL in the phone system as the address it posts call events to.')
						: t('integriq', 'Save the source to get the URL the phone system posts its call events to.')
				}}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * The bindings CtiSourceResolver knows, by the id it switches on. A provider
 * id outside this list resolves to nothing there, so it is not offered here.
 */
export const CTI_PROVIDERS = [
	{ id: 'webhook', label: () => t('integriq', 'Phone system webhook') },
	{ id: 'log', label: () => t('integriq', 'Sandbox (log only)') },
]

/** The call fields the normaliser reads, under the name it reads them. */
export const CTI_CALL_FIELDS = [
	{ key: 'kind', label: () => t('integriq', 'Event kind') },
	{ key: 'callId', label: () => t('integriq', 'Call id') },
	{ key: 'callerNumber', label: () => t('integriq', 'Caller number') },
	{ key: 'agentId', label: () => t('integriq', 'Agent id') },
	{ key: 'at', label: () => t('integriq', 'Time') },
	{ key: 'durationSeconds', label: () => t('integriq', 'Duration in seconds') },
]

/** The kinds CallEvent carries (CallEvent::KIND_*). */
export const CTI_CALL_KINDS = [
	{ id: 'ringing', label: () => t('integriq', 'Ringing') },
	{ id: 'answered', label: () => t('integriq', 'Answered') },
	{ id: 'ended', label: () => t('integriq', 'Ended') },
	{ id: 'transferred', label: () => t('integriq', 'Transferred') },
]

/** The source type a KISS source carries (KissSyncService::SOURCE_TYPE). */
const KISS_SOURCE_TYPE = 'kiss'

/**
 * A stored map, or an empty one when the stored value is not a map.
 *
 * @param {*} value the stored value
 * @return {object} the map
 */
function mapOf(value) {
	return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

export default {
	name: 'CtiSourceFields',

	components: {
		NcSelect,
		NcTextField,
	},

	props: {
		/** The source's configuration object. */
		configuration: { type: Object, default: () => ({}) },
		/** The saved source's id, or empty before the first save. */
		sourceId: { type: String, default: '' },
	},

	emits: ['update:configuration'],

	data() {
		return {
			draft: { ...(this.configuration ?? {}) },
			lookupSourceOptions: [],
			sourcesLoading: false,
		}
	},

	computed: {
		/**
		 * The provider picker options.
		 *
		 * @return {object[]} the options
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		providerOptions() {
			return CTI_PROVIDERS.map((provider) => ({ id: provider.id, label: provider.label() }))
		},

		/**
		 * The option for the stored provider. A stored id this instance has no
		 * binding for is shown as itself, so it is visible rather than empty.
		 *
		 * @return {object|null} the option
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		selectedProvider() {
			const id = String(this.draft.provider ?? '')
			if (id === '') return null
			return this.providerOptions.find((option) => option.id === id) ?? { id, label: id }
		},

		/**
		 * Whether the stored provider is one CtiSourceResolver would refuse.
		 *
		 * @return {boolean} true for an unknown provider id
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		unknownProvider() {
			const id = String(this.draft.provider ?? '')
			return id !== '' && !CTI_PROVIDERS.some((provider) => provider.id === id)
		},

		/**
		 * Whether a secret is set that the picked binding verifies with.
		 *
		 * @return {boolean} true when the source can admit a call
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		hasSecret() {
			const shared = String(this.draft.sharedSecret ?? '') !== ''
			if (this.draft.provider === 'webhook') {
				return shared || String(this.draft.signatureSecret ?? '') !== ''
			}
			return shared
		},

		/**
		 * The call field rows, labelled.
		 *
		 * @return {object[]} the rows
		 * @spec exclude static labelled option list, presentation only
		 */
		callFields() {
			return CTI_CALL_FIELDS.map((field) => ({ key: field.key, label: field.label() }))
		},

		/**
		 * The call kind rows, labelled.
		 *
		 * @return {object[]} the rows
		 * @spec exclude static labelled option list, presentation only
		 */
		callKinds() {
			return CTI_CALL_KINDS.map((kind) => ({ id: kind.id, label: kind.label() }))
		},

		/**
		 * The absolute URL the phone system posts call events to (CtiController::events).
		 *
		 * @return {string} the URL, or an empty string before the first save
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		eventsUrl() {
			const id = String(this.sourceId ?? '')
			if (!/^[A-Za-z0-9-]+$/.test(id)) return ''
			return window.location.origin + generateUrl('/apps/integriq/api/cti/' + id + '/events')
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
		},
	},

	/**
	 * Load the KISS sources the caller lookup picker offers.
	 *
	 * @return {void}
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	created() {
		this.lastEmitted = null
		this.fetchLookupSources()
	},

	methods: {
		t,

		/**
		 * Load the KISS sources on the REST provider: ConfiguredCallerSearch
		 * asks no other kind, so offering one would save a lookup that never
		 * runs. A failed fetch leaves an empty list, never a broken editor.
		 *
		 * @return {Promise<void>} resolves once the list is loaded
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
		 */
		async fetchLookupSources() {
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
				this.lookupSourceOptions = list
					.filter((item) => item?.type === KISS_SOURCE_TYPE && item?.configuration?.provider === 'rest')
					.map((item) => ({
						id: String(item['@self']?.id || item.id || item.uuid),
						label: item.name || item.title || String(item.id),
					}))
			} catch {
				this.lookupSourceOptions = []
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
				this.lookupSourceOptions.find((option) => option.id === String(id)) ?? {
					id: String(id),
					label: String(id),
				}
			)
		},

		/**
		 * A stored value as input text.
		 *
		 * @param {*} value the stored value
		 * @return {string} the text
		 * @spec exclude trivial text projection, presentation only
		 */
		stringOf(value) {
			return value == null ? '' : String(value)
		},

		/**
		 * The vendor words stored for one of our kinds, comma separated.
		 *
		 * @param {string} kind one of CTI_CALL_KINDS
		 * @return {string} the words
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		vendorWordsFor(kind) {
			return Object.entries(mapOf(this.draft.kindMapping))
				.filter(([, ours]) => ours === kind)
				.map(([vendor]) => vendor)
				.join(', ')
		},

		/**
		 * Write one key and emit the whole configuration.
		 *
		 * @param {string} key the configuration key the backend reads
		 * @param {*} value the value
		 * @return {void}
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		write(key, value) {
			this.draft = { ...this.draft, [key]: value }
			this.emitDraft()
		},

		/**
		 * Write one field of the field mapping (our key to the vendor path).
		 * An empty path removes the entry, so the normaliser reads the field
		 * under its own name.
		 *
		 * @param {string} key one of CTI_CALL_FIELDS
		 * @param {string} path the vendor's dotted path
		 * @return {void}
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		writeMapping(key, path) {
			const mapping = { ...mapOf(this.draft.fieldMapping) }
			const trimmed = String(path ?? '').trim()
			if (trimmed === '') {
				delete mapping[key]
			} else {
				mapping[key] = trimmed
			}
			this.write('fieldMapping', mapping)
		},

		/**
		 * Write the vendor words for one of our kinds into the kind mapping,
		 * which the normaliser reads vendor word to ours. The kind's old words
		 * are replaced; the other kinds' words are kept.
		 *
		 * @param {string} kind one of CTI_CALL_KINDS
		 * @param {string} words the vendor words, comma separated
		 * @return {void}
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		writeKind(kind, words) {
			const kindMapping = Object.fromEntries(
				Object.entries(mapOf(this.draft.kindMapping)).filter(([, ours]) => ours !== kind),
			)
			for (const word of String(words ?? '').split(',')) {
				const vendor = word.trim()
				if (vendor !== '') {
					kindMapping[vendor] = kind
				}
			}
			this.write('kindMapping', kindMapping)
		},

		/**
		 * Emit a copy of the draft and remember it, so the watcher does not
		 * reset the draft when the parent hands it back.
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
.cn-cti-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
}

.cn-cti-fields__heading {
	margin: 0;
	font-size: 16px;
}

.cn-cti-fields__label {
	font-weight: bold;
}

.cn-cti-fields__helper {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-cti-fields__note {
	margin: 0;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.cn-cti-fields__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border: none;
	padding: 0;
	margin: 0;
}

.cn-cti-fields__url {
	overflow-wrap: anywhere;
}
</style>
