<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  CtiSourceFields: the settings of a `cti` (telephony) source, shown by
  SourceFormFields when the source type is cti.

  CtiSourceResolver, the two CTI bindings and ConfiguredCallerSearch read
  everything from the source's `configuration`. Each control here writes one
  key under the exact name the backend reads: provider, sharedSecret,
  signatureSecret, eventsPath, fieldMapping, kindMapping and
  callerLookupSource. Keys this component does not know are kept, so nothing
  written elsewhere is lost.

  The binding list mirrors CtiSourceResolver::provider(), which knows exactly
  `log` and `webhook` and refuses any other name rather than falling back.

  kindMapping is stored the way CallEventNormaliser reads it, vendor word to
  our kind (`{ alerting: 'ringing' }`); the form asks the other way round, one
  field per kind of ours, because that is the question an administrator can
  answer from the phone system's documentation.
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
					'The phone system posts its call events to this source. Integriq looks the caller up and tells the case app who is calling.',
				)
			}}
		</p>

		<NcSelect
			inputId="cn-cti-provider"
			:inputLabel="t('integriq', 'Telephony binding')"
			:modelValue="providerOption"
			:options="providerOptions"
			:clearable="false"
			:placeholder="t('integriq', 'Pick a telephony binding')"
			@update:modelValue="(option) => write('provider', option?.id ?? '')" />
		<p
			v-if="!providerOption"
			class="cn-cti-fields__helper cn-cti-fields__helper--warn">
			{{ t('integriq', 'Without a binding every call event is refused.') }}
		</p>

		<div class="cn-cti-fields__address">
			<span class="cn-cti-fields__label">
				{{ t('integriq', 'Events address') }}
			</span>
			<code
				v-if="eventsUrl"
				data-testid="cti-events-url"
				class="cn-cti-fields__code"
				>{{ eventsUrl }}</code
			>
			<p v-else class="cn-cti-fields__helper">
				{{ t('integriq', 'The address appears once the source is saved.') }}
			</p>
		</div>

		<NcTextField
			id="cn-cti-shared-secret"
			:label="t('integriq', 'Shared secret')"
			:modelValue="draft.sharedSecret ?? ''"
			type="password"
			:helperText="
				isWebhook
					? t(
							'integriq',
							'Used when the phone system cannot sign: the value it sends in the X-Api-Key header.',
						)
					: t(
							'integriq',
							'The value a caller sends in the X-Api-Key header. Required: without it every event is refused.',
						)
			"
			@update:modelValue="(value) => write('sharedSecret', value)" />

		<template v-if="isWebhook">
			<NcTextField
				id="cn-cti-signature-secret"
				:label="t('integriq', 'Signature secret')"
				:modelValue="draft.signatureSecret ?? ''"
				type="password"
				:helperText="
					t(
						'integriq',
						'The secret the phone system signs each request body with. Preferred over a shared secret, because a signature covers the body too.',
					)
				"
				@update:modelValue="(value) => write('signatureSecret', value)" />
			<p
				v-if="!hasSecret"
				data-testid="cti-no-secret"
				class="cn-cti-fields__helper cn-cti-fields__helper--warn">
				{{
					t(
						'integriq',
						'Fill in a signature secret or a shared secret. Without one, every event is refused.',
					)
				}}
			</p>

			<NcTextField
				id="cn-cti-events-path"
				:label="t('integriq', 'Events path')"
				:modelValue="draft.eventsPath ?? ''"
				:helperText="
					t(
						'integriq',
						'Dotted path to the list of events in the request body. Leave empty when the body is one event.',
					)
				"
				@update:modelValue="(value) => write('eventsPath', value.trim())" />

			<fieldset class="cn-cti-fields__group">
				<legend class="cn-cti-fields__label">
					{{ t('integriq', 'Field mapping') }}
				</legend>
				<p class="cn-cti-fields__helper">
					{{
						t(
							'integriq',
							'Where each value sits in one event of the phone system, as a dotted path. Leave a field empty when the event already uses our name.',
						)
					}}
				</p>
				<NcTextField
					v-for="field in mappedFields"
					:id="'cn-cti-field-' + field.id"
					:key="field.id"
					:label="field.label"
					:modelValue="fieldMapping[field.id] ?? ''"
					:placeholder="field.id"
					@update:modelValue="(value) => writeField(field.id, value)" />
			</fieldset>

			<fieldset class="cn-cti-fields__group">
				<legend class="cn-cti-fields__label">
					{{ t('integriq', 'Call kinds') }}
				</legend>
				<p class="cn-cti-fields__helper">
					{{
						t(
							'integriq',
							'The word the phone system uses for each kind of call event, such as alerting for ringing. An event of a kind that maps to none of these is ignored.',
						)
					}}
				</p>
				<NcTextField
					v-for="kind in callKinds"
					:id="'cn-cti-kind-' + kind.id"
					:key="kind.id"
					:label="kind.label"
					:modelValue="vendorWords[kind.id] ?? ''"
					:placeholder="kind.id"
					@update:modelValue="(value) => writeKind(kind.id, value)" />
			</fieldset>
		</template>

		<NcSelect
			inputId="cn-cti-caller-lookup-source"
			:inputLabel="t('integriq', 'Look callers up in')"
			:modelValue="lookupOption"
			:options="lookupOptions"
			:loading="sourcesLoading"
			:clearable="true"
			:placeholder="t('integriq', 'Pick a Klantinteracties source')"
			@update:modelValue="
				(option) => write('callerLookupSource', option?.id ?? '')
			" />
		<p class="cn-cti-fields__helper">
			{{
				t(
					'integriq',
					"The Klantinteracties source whose parties are matched against the caller's number. Only an enabled source using the REST binding is asked. Without one, every caller is shown as unknown.",
				)
			}}
		</p>
		<p
			v-if="!sourcesLoading && lookupOptions.length === 0"
			class="cn-cti-fields__helper">
			{{
				t(
					'integriq',
					'There is no Klantinteracties source to pick. Make a source of type KISS first.',
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect, NcTextField } from '@nextcloud/vue'

/** The configuration keys this component writes, as the backend reads them. */
export const CTI_CONFIG_KEYS = Object.freeze([
	'provider',
	'sharedSecret',
	'signatureSecret',
	'eventsPath',
	'fieldMapping',
	'kindMapping',
	'callerLookupSource',
])

/** Our call kinds, CallEventNormaliser::KINDS. */
export const CALL_KINDS = Object.freeze([
	'ringing',
	'answered',
	'ended',
	'transferred',
])

/** The fields the webhook binding maps, WebhookCtiProvider::getConfigSchema(). */
export const MAPPED_FIELDS = Object.freeze([
	'kind',
	'callId',
	'callerNumber',
	'agentId',
	'at',
	'durationSeconds',
])

/**
 * A stored map as a plain object, or an empty one.
 *
 * @param {object|Array|string|number|boolean|null|undefined} value the stored value
 * @return {object} the map
 */
function mapFrom(value) {
	return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

/**
 * Turn the stored vendor-to-ours kind map into ours-to-vendor, for the form.
 *
 * @param {object} kindMapping the stored map, e.g. `{ alerting: 'ringing' }`
 * @return {object} our kind to the vendor word, e.g. `{ ringing: 'alerting' }`
 */
export function vendorWordsFrom(kindMapping) {
	const words = {}
	for (const [vendor, ours] of Object.entries(mapFrom(kindMapping))) {
		if (CALL_KINDS.includes(ours) && words[ours] === undefined) {
			words[ours] = vendor
		}
	}
	return words
}

/**
 * Turn the form's ours-to-vendor words back into the map the backend reads.
 * A word equal to our own kind needs no entry: the normaliser keeps a kind it
 * already knows.
 *
 * @param {object} words our kind to the vendor word
 * @return {object} vendor word to our kind
 */
export function kindMappingFrom(words) {
	const mapping = {}
	for (const kind of CALL_KINDS) {
		const word = String(words[kind] ?? '').trim()
		if (word !== '' && word !== kind) {
			mapping[word] = kind
		}
	}
	return mapping
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
		/** The saved source's id, or '' for a source not saved yet. */
		sourceId: { type: String, default: '' },
	},

	emits: ['update:configuration'],

	data() {
		return {
			draft: { ...(this.configuration ?? {}) },
			vendorWords: vendorWordsFrom(this.configuration?.kindMapping),
			lookupOptions: [],
			sourcesLoading: false,
		}
	},

	computed: {
		/**
		 * The bindings CtiSourceResolver knows.
		 *
		 * @return {object[]} the options
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		providerOptions() {
			return [
				{
					id: 'webhook',
					label: t('integriq', 'Webhook (the phone system posts events)'),
				},
				{
					id: 'log',
					label: t('integriq', 'Log (test events in our own shape)'),
				},
			]
		},

		/**
		 * The option for the stored binding; an unknown name is shown as itself.
		 *
		 * @return {object|null} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		providerOption() {
			const id = String(this.draft.provider ?? '')
			if (id === '') return null
			return (
				this.providerOptions.find((option) => option.id === id) ?? {
					id,
					label: id,
				}
			)
		},

		/**
		 * Whether the webhook binding is picked.
		 *
		 * @return {boolean} true for the webhook binding
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		isWebhook() {
			return this.draft.provider === 'webhook'
		},

		/**
		 * Whether a secret the binding verifies with is filled in.
		 *
		 * @return {boolean} true when a signature or shared secret is set
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		hasSecret() {
			return (
				String(this.draft.signatureSecret ?? '') !== ''
				|| String(this.draft.sharedSecret ?? '') !== ''
			)
		},

		/**
		 * The address the phone system posts to.
		 *
		 * @return {string} the absolute URL, or '' for an unsaved source
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		eventsUrl() {
			if (this.sourceId === '') return ''
			const path = generateUrl(
				'/apps/integriq/api/cti/'
					+ encodeURIComponent(this.sourceId)
					+ '/events',
			)
			return (window.location?.origin ?? '') + path
		},

		/**
		 * The stored field mapping.
		 *
		 * @return {object} our field to the vendor path
		 * @spec exclude trivial projection of the form value, presentation only
		 */
		fieldMapping() {
			return mapFrom(this.draft.fieldMapping)
		},

		/**
		 * The mapped fields, labelled.
		 *
		 * @return {object[]} id and label per field
		 * @spec exclude static labels, presentation only
		 */
		mappedFields() {
			const labels = {
				kind: t('integriq', 'Kind of event'),
				callId: t('integriq', 'Call id'),
				callerNumber: t('integriq', "Caller's number"),
				agentId: t('integriq', 'Agent'),
				at: t('integriq', 'Time of the event'),
				durationSeconds: t('integriq', 'Duration in seconds'),
			}
			return MAPPED_FIELDS.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * Our call kinds, labelled.
		 *
		 * @return {object[]} id and label per kind
		 * @spec exclude static labels, presentation only
		 */
		callKinds() {
			const labels = {
				ringing: t('integriq', 'Ringing'),
				answered: t('integriq', 'Answered'),
				ended: t('integriq', 'Ended'),
				transferred: t('integriq', 'Transferred'),
			}
			return CALL_KINDS.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The option for the stored lookup source; an id not listed is shown as itself.
		 *
		 * @return {object|null} the option
		 * @spec exclude trivial select-value projection, presentation only
		 */
		lookupOption() {
			const id = String(this.draft.callerLookupSource ?? '')
			if (id === '') return null
			return (
				this.lookupOptions.find((option) => option.id === id) ?? {
					id,
					label: id,
				}
			)
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
			this.vendorWords = vendorWordsFrom(next?.kindMapping)
		},
	},

	/**
	 * Load the Klantinteracties sources the lookup picker offers.
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
		 * Load the instance's KISS sources. A failed fetch leaves an empty
		 * list, never a broken editor.
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
					{ params: { type: 'kiss', _limit: 500 } },
				)
				const list = Array.isArray(response?.data?.results)
					? response.data.results
					: []
				this.lookupOptions = list
					.filter((item) => item?.type === 'kiss')
					.map((item) => ({
						id: String(item['@self']?.id || item.id || item.uuid),
						label: item.name || item.title || String(item.id),
					}))
			} catch {
				this.lookupOptions = []
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * Write one key and emit the whole configuration.
		 *
		 * @param {string} key the configuration key the backend reads
		 * @param {string|object} value the value
		 * @return {void}
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		write(key, value) {
			this.draft = { ...this.draft, [key]: value }
			this.emitDraft()
		},

		/**
		 * Write the vendor path of one mapped field. An empty path removes the
		 * entry, so the event's own field name is read.
		 *
		 * @param {string} field our field name
		 * @param {string} value the dotted vendor path
		 * @return {void}
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		writeField(field, value) {
			const mapping = { ...this.fieldMapping }
			const path = String(value ?? '').trim()
			if (path === '') {
				delete mapping[field]
			} else {
				mapping[field] = path
			}
			this.write('fieldMapping', mapping)
		},

		/**
		 * Write the vendor word for one of our kinds, as the vendor-to-ours map.
		 *
		 * @param {string} kind our kind
		 * @param {string} value the vendor word
		 * @return {void}
		 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
		 */
		writeKind(kind, value) {
			this.vendorWords = { ...this.vendorWords, [kind]: String(value ?? '') }
			this.write('kindMapping', kindMappingFrom(this.vendorWords))
		},

		/**
		 * Emit a copy of the draft and remember it, so the watcher does not
		 * reset the form when the parent hands it back.
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

.cn-cti-fields__helper--warn {
	color: var(--color-warning-text, var(--color-main-text));
}

.cn-cti-fields__address {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-cti-fields__code {
	overflow-wrap: anywhere;
	padding: 4px 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.cn-cti-fields__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border: none;
	padding: 0;
	margin: 0;
}
</style>
