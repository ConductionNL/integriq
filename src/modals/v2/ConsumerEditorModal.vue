<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ConsumerEditorModal — the create/edit surface for a Consumer on the Consumers
  index.

  It restores the field set the pre-manifest modal
  (`src/modals/Consumer/EditConsumer.vue`, deleted in `c999f8fd`) offered, and
  adds the rate-limit/quota block `REQ-CON-RL-005` has required since the
  consumer-rate-limiting change:

      name │ description

      ┌─────────────────────────────────────────────┐
      │ Allowed sources    domains │ ips  (chips)   │
      └─────────────────────────────────────────────┘

      authorization type
      ┌─────────────────────────────────────────────┐
      │ Authorization configuration  (JSON)         │
      │   — only for a type that carries one        │
      └─────────────────────────────────────────────┘

      rate limit (requests │ window) │ quota (limit │ period)

  ## Why the whole dialog, not a `form-fields` slot

  Not for width, unlike MappingEditorModal / RuleEditorModal /
  SynchronizationEditorModal. This one replaces the dialog because it has to own
  the **submit payload**, and `form-fields` only replaces the field rendering —
  `initFormData()` and `buildSubmitPayload()` both live in CnFormDialog, above
  the slot.

  `domains` and `ips` are bare `type: array` properties, so `resolveWidget()`
  maps them to the `tags` widget, and `initFormData()` seeds every `tags` field
  to `[]` on create. `ConsumerScopeService::isAllowed()` gates on `is_array()`,
  so `[]` is "an allowlist that admits nobody", not "no allowlist" — every
  consumer created through the generic dialog would have been born rejecting all
  inbound traffic with a 403 pointing nowhere near the form that caused it.
  OpenRegister does not save us: it filters empty non-required arrays out of a
  validation COPY only, so the row keeps `[]`.

  `buildConsumerPayload()` in `consumerDraft.js` is that fix, and carries the
  full three-state table plus the two other omission rules (write-only
  credential preservation, explicit-null limit clearing).

  ## Fields the generic dialog also could not express

    • `authorizationType` is an enum-less string on the `consumer` schema, so
      `resolveWidget()` fell through to `text` and offered a free-text box for a
      closed vocabulary. It is a select here, over `AUTHORIZATION_TYPES`.

    • `authorizationConfiguration`, `rateLimit` and `quota` are `type: object`,
      and `fieldsFromSchema()` drops plain objects carrying no `widget` hint —
      so none of the three was on the form at all. (The register fragment gives
      them `widget: 'json'` for the DETAIL page's data widget, which does read
      the schema; this modal renders them itself.)

    • `rateLimit`/`quota` are edited as four typed scalars rather than as JSON,
      which is what REQ-CON-RL-005 asks for ("requests per window + window
      seconds", "limit + period"). `fieldsFromSchema()` walks top-level
      properties only, so no nested leaf is reachable declaratively.

  ## Write-only credential

  `authorizationConfiguration` is `writeOnly: true`
  (`register.d/99-consumer-secrets-writeonly.json`), so OpenRegister strips it
  from every response — admin included. The editor therefore ALWAYS opens blank
  on edit; it is not a rendering bug and must not be "fixed" by fetching the
  value. `authConfig` stays `undefined` until the operator types, and an
  untouched credential is omitted from the payload so OpenRegister's preserve
  rule carries the stored one forward. Clearing is deliberate and explicit, via
  the Clear button. This is the integriq#245 shape — the same defect on
  Sources is what put the preserve rule in OpenRegister.

  ## How it is mounted

  Through CnIndexPage's `form-dialog` slot, declared in the manifest as
  `pages[Consumers].slots["form-dialog"]`. Slot content always renders, so
  unlike the default CnFormDialog the `show` gate has to be ours.

  Every edit lands in a local `draft`, seeded from `item` when the dialog opens;
  nothing is persisted until Save, which goes through the slot's `confirm`
  binding so the index's own save path — and its list refresh — runs.

  @spec openspec/specs/consumer-management/spec.md
-->
<template>
	<NcDialog
		v-if="show"
		:name="dialogTitle"
		size="normal"
		class="cn-consumer-editor-modal"
		:noClose="saving"
		@closing="onCancel">
		<div class="cn-consumer-editor">
			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>

			<!-- Identity — the consumer's own metadata, above the policy sections. -->
			<div class="cn-consumer-editor__identity">
				<NcTextField
					:modelValue="draft.name"
					:label="nameLabel"
					:error="!!nameError"
					:helperText="nameError"
					maxlength="255"
					:disabled="saving"
					@update:modelValue="updateDraft('name', $event)"
					@blur="nameTouched = true" />
				<NcTextArea
					:modelValue="draft.description"
					:label="t('integriq', 'Description')"
					:disabled="saving"
					rows="2"
					resize="vertical"
					@update:modelValue="updateDraft('description', $event)" />
			</div>

			<!-- ── Allowed sources ─────────────────────────────────────── -->
			<section class="cn-consumer-editor__section">
				<header class="cn-consumer-editor__section-header">
					<ShieldCheckOutlineIcon :size="20" />
					<h3>{{ t('integriq', 'Allowed sources') }}</h3>
				</header>

				<div class="cn-consumer-editor__grid">
					<div class="cn-consumer-editor__field">
						<label
							for="cn-consumer-editor-domains"
							class="cn-consumer-editor__label">
							{{ t('integriq', 'Allowed domains') }}
						</label>
						<NcSelect
							inputId="cn-consumer-editor-domains"
							:modelValue="draft.domains"
							:options="[]"
							:multiple="true"
							:taggable="true"
							:keepOpen="true"
							:clearable="true"
							:disabled="saving"
							:aria-label-combobox="t('integriq', 'Allowed domains')"
							:placeholder="domainPlaceholder"
							@update:modelValue="updateDraft('domains', $event)" />
						<span class="cn-consumer-editor__helper">
							{{
								t(
									'integriq',
									"Press Enter after each entry. Exact hostname, or a suffix wildcard like *.example.com (which also matches example.com). Matched against the caller's verified reverse DNS, never against a header.",
								)
							}}
						</span>
					</div>

					<div class="cn-consumer-editor__field">
						<label
							for="cn-consumer-editor-ips"
							class="cn-consumer-editor__label">
							{{ t('integriq', 'Allowed IPs') }}
						</label>
						<NcSelect
							inputId="cn-consumer-editor-ips"
							:modelValue="draft.ips"
							:options="[]"
							:multiple="true"
							:taggable="true"
							:keepOpen="true"
							:clearable="true"
							:disabled="saving"
							:aria-label-combobox="t('integriq', 'Allowed IPs')"
							:placeholder="ipPlaceholder"
							@update:modelValue="updateDraft('ips', $event)" />
						<span class="cn-consumer-editor__helper">
							{{
								t(
									'integriq',
									'Press Enter after each entry. Exact IPv4/IPv6 address or a CIDR range.',
								)
							}}
						</span>
					</div>
				</div>

				<NcNoteCard :type="hasAllowlist ? 'info' : 'warning'">
					<p v-if="hasAllowlist">
						{{
							t(
								'integriq',
								'The two lists combine as a union — a caller is admitted when it matches an entry in either one, and rejected with HTTP 403 otherwise.',
							)
						}}
					</p>
					<p v-else>
						{{
							t(
								'integriq',
								'Both lists are empty, so this consumer may call from any source. Add an entry to restrict it.',
							)
						}}
					</p>
				</NcNoteCard>
			</section>

			<!-- ── Authentication ──────────────────────────────────────── -->
			<section class="cn-consumer-editor__section">
				<header class="cn-consumer-editor__section-header">
					<KeyOutlineIcon :size="20" />
					<h3>{{ t('integriq', 'Authentication') }}</h3>
				</header>

				<div
					class="cn-consumer-editor__field cn-consumer-editor__field--narrow">
					<label
						for="cn-consumer-editor-auth-type"
						class="cn-consumer-editor__label">
						{{ t('integriq', 'Authorization type') }}
					</label>
					<NcSelect
						inputId="cn-consumer-editor-auth-type"
						:modelValue="selectedAuthorizationType"
						:options="authorizationTypeOptions"
						:clearable="false"
						:disabled="saving"
						:aria-label-combobox="t('integriq', 'Authorization type')"
						@update:modelValue="onAuthorizationTypePick" />
					<span class="cn-consumer-editor__helper">
						{{ authorizationTypeHelper }}
					</span>
				</div>

				<div v-if="carriesCredential" class="cn-consumer-editor__field">
					<!-- A span, not a <label for>: CnJsonViewer wraps CodeMirror, whose
					     focusable element is generated internally and has no stable id
					     to point at, so a `for` here would resolve to nothing. -->
					<span class="cn-consumer-editor__label">
						{{ t('integriq', 'Authorization configuration') }}
					</span>
					<CnJsonViewer
						:value="authConfigText"
						language="json"
						height="160px"
						:readOnly="saving"
						:errorText="authConfigError"
						@update:value="onAuthConfigInput" />
					<span class="cn-consumer-editor__helper">
						{{ authConfigHelper }}
					</span>
					<div class="cn-consumer-editor__helper-actions">
						<NcButton
							variant="tertiary"
							size="small"
							:disabled="saving || !authConfigPlaceholder"
							@click="insertAuthConfigTemplate">
							{{ t('integriq', 'Insert example') }}
						</NcButton>
						<NcButton
							v-if="!isCreate"
							variant="tertiary"
							size="small"
							:disabled="saving || authConfigCleared"
							@click="clearAuthConfig">
							{{ t('integriq', 'Clear stored credential') }}
						</NcButton>
					</div>
					<NcNoteCard v-if="authConfigCleared" type="warning">
						<p>
							{{
								t(
									'integriq',
									'The stored credential will be removed when you save.',
								)
							}}
						</p>
					</NcNoteCard>
				</div>
			</section>

			<!-- ── Data feeds (gateway-declared-data-feeds) ─────────────── -->
			<section class="cn-consumer-editor__section">
				<header class="cn-consumer-editor__section-header">
					<ShieldCheckOutlineIcon :size="20" />
					<h3>{{ t('integriq', 'Data feeds') }}</h3>
				</header>

				<NcTextArea
					:modelValue="draft.feedScopesText"
					:label="t('integriq', 'Feed scopes')"
					:disabled="saving"
					:error="feedScopesError !== ''"
					:helperText="feedScopesHelper"
					placeholder="shillinq: 0f3c…, 9a1d…"
					resize="vertical"
					data-testid="consumer-feed-scopes"
					@update:modelValue="updateDraft('feedScopesText', $event)" />
			</section>

			<!-- ── Limits ──────────────────────────────────────────────── -->
			<section class="cn-consumer-editor__section">
				<header class="cn-consumer-editor__section-header">
					<SpeedometerIcon :size="20" />
					<h3>{{ t('integriq', 'Limits') }}</h3>
				</header>

				<div class="cn-consumer-editor__grid">
					<div class="cn-consumer-editor__field">
						<NcInputField
							:modelValue="
								numberText(draft.rateLimitRequestsPerWindow)
							"
							type="number"
							min="1"
							:label="t('integriq', 'Requests per window')"
							:disabled="saving"
							placeholder="60"
							@update:modelValue="
								updateNumber('rateLimitRequestsPerWindow', $event)
							" />
					</div>
					<div class="cn-consumer-editor__field">
						<NcInputField
							:modelValue="numberText(draft.rateLimitWindowSeconds)"
							type="number"
							min="1"
							:label="t('integriq', 'Window (seconds)')"
							:disabled="saving"
							placeholder="60"
							@update:modelValue="
								updateNumber('rateLimitWindowSeconds', $event)
							" />
					</div>
					<div class="cn-consumer-editor__field">
						<NcInputField
							:modelValue="numberText(draft.quotaLimit)"
							type="number"
							min="1"
							:label="t('integriq', 'Quota limit')"
							:disabled="saving"
							placeholder="10000"
							@update:modelValue="
								updateNumber('quotaLimit', $event)
							" />
					</div>
					<div class="cn-consumer-editor__field">
						<label
							for="cn-consumer-editor-quota-period"
							class="cn-consumer-editor__label">
							{{ t('integriq', 'Quota period') }}
						</label>
						<NcSelect
							inputId="cn-consumer-editor-quota-period"
							:modelValue="selectedQuotaPeriod"
							:options="quotaPeriodOptions"
							:clearable="true"
							:disabled="saving"
							:aria-label-combobox="t('integriq', 'Quota period')"
							@update:modelValue="
								updateDraft('quotaPeriod', $event?.id ?? null)
							" />
					</div>
				</div>

				<span class="cn-consumer-editor__helper">
					{{
						t(
							'integriq',
							'Both halves of a pair are needed for it to apply — a half-filled rate limit or quota is saved as unlimited. Leave all four empty for no limits at all.',
						)
					}}
				</span>
			</section>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<EditorSaveButton
				:disabled="!canSave"
				:saving="saving"
				:isCreate="isCreate"
				@click="onSave" />
		</template>
	</NcDialog>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcInputField,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import ShieldCheckOutlineIcon from 'vue-material-design-icons/ShieldCheckOutline.vue'
import SpeedometerIcon from 'vue-material-design-icons/Speedometer.vue'
import EditorSaveButton from './EditorSaveButton.vue'
import confirmSave from '../../mixins/confirmSave.js'
import namedEditorDialog from '../../mixins/namedEditorDialog.js'
import { editorSlotScopeProps } from '../editorSlotScope.js'
import {
	AUTHORIZATION_TYPES,
	buildConsumerPayload,
	consumerDraftFromItem,
	emptyConsumerDraft,
	normaliseList,
	parseFeedScopes,
	positiveIntOrNull,
	QUOTA_PERIODS,
	// Aliased: the computed below keeps the bare name for the template's sake.
	carriesCredential as typeCarriesCredential,
} from './consumerDraft.js'

/**
 * Per-type example credential shapes, keyed by authorization type.
 *
 * Code-shaped examples, deliberately not run through `t()` — the keys are the
 * literal property names `AuthorizationService` reads
 * (`authorizationConfiguration.apiKey`, and `.publicKey`/`.algorithm` on the
 * JWT issuer path), so translating them would produce a credential the engine
 * cannot read. Types with no engine path get no example.
 */
const AUTH_CONFIG_TEMPLATES = {
	apiKey: '{\n\t"apiKey": ""\n}',
	jwt: '{\n\t"publicKey": "",\n\t"algorithm": "RS256"\n}',
}

/**
 * Chip-input placeholders. Literal address/hostname examples, deliberately not
 * run through `t()` for the same reason as AUTH_CONFIG_TEMPLATES: they are
 * syntax, not prose, and a localised `example.com` would be a worse example
 * rather than a translated one. The prose that explains the syntax IS
 * translated, in the helper line under each input.
 */
const DOMAIN_PLACEHOLDER = 'example.com, *.example.org'
const IP_PLACEHOLDER = '203.0.113.4, 10.0.0.0/8'

/**
 * The helper text under the authorization type select, per type.
 *
 * @param {string|undefined} type The chosen authorization type.
 * @return {string} The translated helper text.
 */
function authorizationTypeHelp(type) {
	if (type === 'apiKey') {
		return t(
			'integriq',
			'Inbound callers present a key, matched against the key below under a constant-time comparison.',
		)
	}
	if (type === 'jwt') {
		return t(
			'integriq',
			"Inbound callers present a JWT whose issuer matches this consumer's name, verified with the public key below.",
		)
	}
	return otherAuthorizationTypeHelp(type)
}

/**
 * The helper text for the types that carry no key of their own.
 *
 * @param {string|undefined} type The chosen authorization type.
 * @return {string} The translated helper text.
 */
function otherAuthorizationTypeHelp(type) {
	if (type === 'none') {
		return t(
			'integriq',
			'No credential is checked. Restrict access with the allowed sources above instead.',
		)
	}
	return t(
		'integriq',
		'The credential this consumer presents is verified against the configuration below.',
	)
}

/**
 * Why a typed credential configuration cannot be stored, or '' when it can.
 *
 * @param {string} text The trimmed configuration text.
 * @return {string} The translated problem, or ''.
 */
function authConfigProblem(text) {
	try {
		return isJsonObject(JSON.parse(text))
			? ''
			: t('integriq', 'The authorization configuration must be a JSON object.')
	} catch (err) {
		return err?.message || t('integriq', 'Invalid JSON.')
	}
}

/**
 * Whether a parsed JSON value is an object (not null, not a list).
 *
 * @param {unknown} parsed The parsed value.
 * @return {boolean} True for a plain JSON object.
 */
function isJsonObject(parsed) {
	return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
}

/** A name has to carry at least one letter or digit — punctuation alone is not a name. */
const NAME_PATTERN = /[\p{L}\p{N}]/u

export default {
	name: 'ConsumerEditorModal',

	components: {
		EditorSaveButton,
		CnJsonViewer,
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		KeyOutlineIcon,
		ShieldCheckOutlineIcon,
		SpeedometerIcon,
	},

	mixins: [namedEditorDialog, confirmSave],

	props: { ...editorSlotScopeProps },

	data() {
		return {
			draft: emptyConsumerDraft(),
			/**
			 * Whether the name field has been left at least once. The "required"
			 * message waits for this so a freshly-opened Create dialog does not
			 * greet the user with a red empty field.
			 */
			nameTouched: false,
			/**
			 * Verbatim editor contents for the credential. Empty string means
			 * "the operator has typed nothing", which is NOT the same as
			 * "clear it" — see `authConfigForPayload`.
			 */
			authConfigText: '',
			authConfigError: '',
			/** True once Clear was pressed; sends an explicit null on save. */
			authConfigCleared: false,
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/**
		 * @return {string} Untranslated chip-input example for domains.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		domainPlaceholder() {
			return DOMAIN_PLACEHOLDER
		},

		/**
		 * @return {string} Untranslated chip-input example for IPs.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		ipPlaceholder() {
			return IP_PLACEHOLDER
		},

		/**
		 * @return {string} Dialog heading.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		dialogTitle() {
			return this.isCreate
				? t('integriq', 'Create consumer')
				: t('integriq', 'Edit consumer')
		},

		/**
		 * @return {string} Validation message for the name field, or ''.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		nameError() {
			if (!this.nameTouched) return ''
			return NAME_PATTERN.test(this.draft.name || '')
				? ''
				: t('integriq', 'A name is required.')
		},

		/**
		 * @return {boolean} True when either allowlist has an entry.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		hasAllowlist() {
			return (
				normaliseList(this.draft.domains).length > 0
				|| normaliseList(this.draft.ips).length > 0
			)
		},

		/**
		 * Shares its definition with `buildConsumerPayload()`'s nulling rule, so
		 * the editor is visible for exactly the types whose credential a save can
		 * retire. A stored type this picker does not offer — a casing variant, or
		 * anything else written through the API — therefore reveals the editor
		 * rather than hiding a credential the save would then destroy.
		 *
		 * @return {boolean} True when the chosen type carries a credential.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		carriesCredential() {
			return typeCarriesCredential(this.draft.authorizationType)
		},

		/**
		 * @return {Array<{id: string, label: string}>} Authorization type options.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		authorizationTypeOptions() {
			const labels = {
				none: t('integriq', 'None — no authentication'),
				basic: t('integriq', 'Basic authentication'),
				bearer: t('integriq', 'Bearer token'),
				apiKey: t('integriq', 'API key'),
				oauth2: t('integriq', 'OAuth 2.0'),
				jwt: t('integriq', 'JWT'),
			}
			return AUTHORIZATION_TYPES.map((id) => ({ id, label: labels[id] || id }))
		},

		/**
		 * The selected option object NcSelect matches by reference, or a
		 * synthesised one for a stored value that is not on the list — so an
		 * unrecognised persisted type displays instead of reading as unset, and
		 * saving an unrelated field cannot silently rewrite it.
		 *
		 * @return {object|null} The selected option.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		selectedAuthorizationType() {
			const current = this.draft.authorizationType
			if (!current) return null
			return (
				this.authorizationTypeOptions.find(
					(option) => option.id === current,
				) ?? { id: current, label: current }
			)
		},

		/**
		 * @return {string} Helper text under the type picker.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		authorizationTypeHelper() {
			return authorizationTypeHelp(this.draft.authorizationType)
		},

		/**
		 * @return {string} The example shape for the chosen type, or ''.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		authConfigPlaceholder() {
			return AUTH_CONFIG_TEMPLATES[this.draft.authorizationType] || ''
		},

		/**
		 * @return {string} Helper text under the credential editor.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		authConfigHelper() {
			if (this.isCreate) {
				return t(
					'integriq',
					'Stored write-only: it is accepted on save but never returned by any API read.',
				)
			}
			return t(
				'integriq',
				'Write-only, so it always opens empty — that is not a missing value. Leave it empty to keep the stored credential unchanged.',
			)
		},

		/**
		 * @return {Array<{id: string, label: string}>} Quota period options.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		quotaPeriodOptions() {
			const labels = {
				hour: t('integriq', 'Per hour'),
				day: t('integriq', 'Per day'),
				month: t('integriq', 'Per month'),
			}
			return QUOTA_PERIODS.map((id) => ({ id, label: labels[id] || id }))
		},

		/**
		 * @return {object|null} The selected quota-period option.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		selectedQuotaPeriod() {
			const current = this.draft.quotaPeriod
			if (!current) return null
			return (
				this.quotaPeriodOptions.find((option) => option.id === current)
				?? null
			)
		},

		/**
		 * What to hand `buildConsumerPayload()` for the credential:
		 * `undefined` leaves the stored value untouched, `null` clears it, an
		 * object replaces it.
		 *
		 * @return {object|null|undefined} The credential intent.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		authConfigForPayload() {
			if (this.authConfigCleared) return null
			if (!this.authConfigText.trim()) return undefined
			try {
				return JSON.parse(this.authConfigText)
			} catch (_e) {
				return undefined
			}
		},

		/**
		 * @return {boolean} Whether Save is enabled.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		canSave() {
			return (
				!this.saving
				&& typeof this.confirm === 'function'
				&& NAME_PATTERN.test(this.draft.name || '')
				&& !this.authConfigError
				&& !this.feedScopesError
			)
		},

		/**
		 * The line of the feed-scope text that cannot be read, or ''.
		 *
		 * @return {string} The line number, or ''.
		 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003
		 */
		feedScopesError() {
			return parseFeedScopes(this.draft.feedScopesText).error
		},

		/**
		 * What the feed-scope field means, or which line is wrong.
		 *
		 * @return {string} The translated text.
		 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003
		 */
		feedScopesHelper() {
			if (this.feedScopesError !== '') {
				return t('integriq', 'Line {line} is not "app: value, value".', { line: this.feedScopesError })
			}
			return t('integriq', 'One line per app that declares data feeds, with the values this consumer may read, e.g. its administration ids. Without a line for an app, every feed of that app refuses this consumer.')
		},
	},

	methods: {
		t,

		/**
		 * The record to save.
		 *
		 * @return {object} The payload for the slot's `confirm` binding.
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		savePayload() {
			return buildConsumerPayload(
				this.item,
				this.draft,
				this.authConfigForPayload,
			)
		},

		/**
		 * The toast after a save.
		 *
		 * @return {string} The translated text.
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		savedText() {
			return this.isCreate
				? t('integriq', 'Consumer created')
				: t('integriq', 'Consumer saved')
		},

		/**
		 * The reason shown when a save fails without one.
		 *
		 * @return {string} The translated text.
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		saveFailedText() {
			return t('integriq', 'Failed to save consumer')
		},

		/**
		 * Copy the row being edited into the local draft. The credential is
		 * never seeded — it is write-only and simply absent from `item`.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		seedDraft() {
			this.draft = consumerDraftFromItem(this.item)
			this.nameTouched = false
			this.authConfigText = ''
			this.authConfigError = ''
			this.authConfigCleared = false
			this.saving = false
			this.saveError = ''
		},

		/**
		 * Commit one draft field.
		 *
		 * @param {string} key   Draft key.
		 * @param {*}      value New value.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		updateDraft(key, value) {
			this.draft = { ...this.draft, [key]: value }
			this.saveError = ''
		},

		/**
		 * Commit one of the four limit scalars, coerced to a positive integer or
		 * null. Coercing on input (rather than on save) means the field shows
		 * what will actually be persisted.
		 *
		 * @param {string} key   Draft key.
		 * @param {string} value Raw input value.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		updateNumber(key, value) {
			this.updateDraft(key, positiveIntOrNull(value))
		},

		/**
		 * Render a limit scalar for its number input. `null` renders as empty
		 * rather than as `0`, so "unset" and "zero" stay visually distinct.
		 *
		 * @param {number|null} value The stored scalar.
		 *
		 * @return {string} Input value.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		numberText(value) {
			return value === null || value === undefined ? '' : String(value)
		},

		/**
		 * Switch authorization type. Picking a type that carries no credential
		 * drops whatever was typed into the editor — that value could only ever
		 * be written back as a credential the engine would not read, and
		 * `buildConsumerPayload()` nulls the property for such types anyway.
		 *
		 * @param {object|null} option The chosen option.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		onAuthorizationTypePick(option) {
			this.updateDraft('authorizationType', option?.id || 'none')
			if (!this.carriesCredential) {
				this.authConfigText = ''
				this.authConfigError = ''
				this.authConfigCleared = false
			}
		},

		/**
		 * Validate the credential editor on every keystroke. The text is kept
		 * verbatim so invalid intermediate typing is not clobbered; only
		 * `authConfigForPayload` parses it.
		 *
		 * @param {string} value Current editor contents.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		onAuthConfigInput(value) {
			this.authConfigText = value
			this.authConfigCleared = false
			this.saveError = ''
			const trimmed = (value || '').trim()
			this.authConfigError = trimmed ? authConfigProblem(trimmed) : ''
		},

		/**
		 * Drop the example shape for the current type into the editor.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		insertAuthConfigTemplate() {
			this.onAuthConfigInput(this.authConfigPlaceholder)
		},

		/**
		 * Mark the stored credential for removal on save.
		 *
		 * @spec openspec/specs/consumer-management/spec.md
		 */
		clearAuthConfig() {
			this.authConfigText = ''
			this.authConfigError = ''
			this.authConfigCleared = true
		},
	},
}
</script>

<style scoped>
.cn-consumer-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
	margin-block-end: 0.5rem;
}

/* Stacked rather than two-up: the differing control heights of an input next to
   a textarea would leave a gap beside the shorter one. Full width — every other
   section fills the dialog, so a capped identity block reads as truncated. */
.cn-consumer-editor__identity {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-consumer-editor__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block-start: 8px;
	border-block-start: 1px solid var(--color-border);
}

.cn-consumer-editor__section-header {
	display: flex;
	gap: 8px;
	align-items: center;
}

.cn-consumer-editor__section-header h3 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
}

.cn-consumer-editor__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 768px) {
	.cn-consumer-editor__grid {
		grid-template-columns: 1fr;
	}
}

.cn-consumer-editor__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.cn-consumer-editor__field--narrow {
	max-width: 320px;
}

.cn-consumer-editor__label {
	font-weight: 500;
}

.cn-consumer-editor__helper {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.cn-consumer-editor__helper-actions {
	display: flex;
	gap: 4px;
}
</style>
