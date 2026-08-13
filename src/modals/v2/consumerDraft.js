/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Draft/option/payload logic for `modals/v2/ConsumerEditorModal.vue`, the
 * create/edit surface on the Consumers index.
 *
 * Kept out of the SFC so it is directly unit-testable: `src/modals/**` is
 * pruned by `eslint src` before `eslint.config.js`'s `!src/modals/v2/**`
 * negation can fire, so a vitest suite over pure functions is the compensating
 * cover here (same arrangement as `jobDraft.js`).
 *
 * ## Why this module exists at all
 *
 * `buildConsumerPayload()` is the reason the Consumers page replaces the whole
 * `CnFormDialog` instead of just its content. A consumer's `domains`/`ips`
 * allowlist has THREE states the backend distinguishes, and the generic dialog
 * can only ever emit two of them:
 *
 *   | value      | ConsumerScopeService::isAllowed()             |
 *   |------------|----------------------------------------------|
 *   | absent     | unrestricted — every source allowed          |
 *   | `[]`       | configured, matches nothing — 403 everything  |
 *   | `['a.com']`| configured — that entry allowed              |
 *
 * `isAllowed()` gates on `is_array()`, so `[]` is "an allowlist that admits
 * nobody", not "no allowlist". `CnFormDialog.initFormData()` seeds every
 * `tags`-widget field to `[]` on create and `buildSubmitPayload()` spreads
 * formData verbatim, so a create through the generic dialog submits
 * `domains: []` and `ips: []` — and every consumer authored in the UI would be
 * born rejecting all inbound traffic with a 403 that points nowhere near the
 * form that caused it. Nothing downstream rescues it: OpenRegister DOES filter
 * empty non-required arrays, but only from a validation COPY
 * (`$validationData = $sanitised` in `SaveObjects.php`), so the persisted row
 * keeps `[]`.
 *
 * A `form-fields` slot could not have fixed this — `initFormData` and
 * `buildSubmitPayload` both live in the dialog, above the slot. Owning the
 * payload is the whole point.
 *
 * @see openspec/specs/consumer-management/spec.md — REQ-CON-SCOPE-001
 */

/**
 * Authorization types offered by the type picker.
 *
 * VALUES only — the labels live in the SFC as `t()` literals so
 * `tests/l10n/check-l10n.js` can see them (a `t(variable)` at module scope is
 * invisible to string extraction, and a label map in the manifest would be
 * permanently untranslatable).
 *
 * Deliberately NOT an `enum` on the `consumer` schema: an enum is enforced on
 * save, and it would reject legitimate casing variants —
 * `AuthorizationService::resolveConsumerByApiKey()` compares
 * `strtolower($data['authorizationType']) !== 'apikey'`, so stored data may hold
 * `apiKey` or `apikey` and both authenticate. An enum would make the second one
 * unsaveable while leaving it working, which is the worst of both.
 *
 * NOT because of the three seeded consumers, which an earlier version of this
 * comment claimed: they declare no `authorizationType` at all
 * (`openconnector_seed_data.json`), and `SaveObjects` validates the submitted
 * row before `SaveObject::fillMissingSchemaPropertiesWithNull()` materialises
 * the absent property, so an enum never sees them. The casing argument carries
 * this on its own.
 *
 * `apiKey` is the canonical casing: it is what the deleted `EditConsumer.vue`
 * offered and what `NotificatiesSubscriberService::provisionConsumer()` writes.
 *
 * `bearer` carries no engine path — nothing reads it — and is offered only for
 * parity with the list the pre-cutover modal had. See the change's Non-goals.
 */
export const AUTHORIZATION_TYPES = ['none', 'basic', 'bearer', 'apiKey', 'oauth2', 'jwt']

/**
 * Authorization types that carry NO credential — the only ones for which the
 * editor stays hidden and `buildConsumerPayload()` retires a stored credential.
 *
 * A DENY-list, deliberately, and this is the one place the direction matters.
 * The obvious shape is an allow-list of the five credential-bearing types, and
 * that is what this was — but `AUTHORIZATION_TYPES` above documents why stored
 * data may hold a value that is not on any list of ours (no schema `enum`,
 * `resolveConsumerByApiKey()` matching case-insensitively), and
 * `consumerDraftFromItem()` deliberately keeps such a value verbatim. Under an
 * allow-list those two decisions combined into a silent credential wipe: a
 * consumer stored as `apikey` failed the membership test, so the editor and its
 * Clear button never rendered, and rule 3 below then sent
 * `authorizationConfiguration: null` on any unrelated edit — retiring a working
 * key from a form that never showed it. Exactly the class of silent destruction
 * this module exists to prevent, arrived at from the other direction.
 *
 * Inverting it makes an unrecognised type fail SAFE: it reveals the editor
 * (harmless — leaving it blank still omits the key) and never nulls. That
 * matters beyond casing, because the JWT issuer path does not gate on the type
 * at all — `findIssuer()` filters on the consumer's name alone and reads
 * `authorizationConfiguration.publicKey` whatever the type says — so an
 * off-list value on a working issuer was destroyable too.
 *
 * `''` is listed alongside `'none'` so the absent type of a freshly-opened
 * create dialog still keeps the editor hidden rather than showing it before a
 * type is even chosen — the one property the allow-list had that was worth
 * keeping. Compared lower-cased, so `None` reads as `none`.
 */
export const CREDENTIALLESS_AUTHORIZATION_TYPES = ['', 'none']

/**
 * Whether an authorization type carries a credential.
 *
 * Drives both the editor's visibility and rule 3 of `buildConsumerPayload()`,
 * from one definition — they disagreed once, and the disagreement was invisible
 * precisely because the hidden editor was the thing that hid it.
 *
 * @param {string|null|undefined} authorizationType The stored or drafted type.
 *
 * @return {boolean} True when the type carries a credential.
 */
export function carriesCredential(authorizationType) {
	const type = String(authorizationType ?? '').trim().toLowerCase()
	return !CREDENTIALLESS_AUTHORIZATION_TYPES.includes(type)
}

/**
 * Quota reset periods. Mirrors the `enum` on `consumer.quota.period` in
 * `lib/Settings/openconnector_register.json` — asserted equal in
 * `tests/vitest/consumerDraft.spec.js` so the two cannot drift.
 */
export const QUOTA_PERIODS = ['hour', 'day', 'month']

/**
 * A fresh, empty draft.
 *
 * `domains`/`ips` start as their own arrays (never a shared module constant —
 * the chip inputs replace them wholesale, but a caller pushing in place would
 * otherwise leak between dialog openings). `rateLimit`/`quota` are flattened
 * into four scalars because the form edits leaves, not JSON;
 * `buildConsumerPayload()` reassembles the two nested objects.
 *
 * @return {object} A new empty consumer draft.
 */
export function emptyConsumerDraft() {
	return {
		name: '',
		description: '',
		domains: [],
		ips: [],
		authorizationType: 'none',
		rateLimitRequestsPerWindow: null,
		rateLimitWindowSeconds: null,
		quotaLimit: null,
		quotaPeriod: null,
	}
}

/**
 * Coerce a persisted allowlist into the `string[]` the chip input needs.
 *
 * Accepts a string as well as an array on purpose: the pre-cutover
 * `src/modals/Consumer/EditConsumer.vue` drove these two properties with a
 * textarea and persisted whatever the operator typed, so rows written before
 * the OpenRegister cutover can legitimately hold `'example.com, example.org'`.
 * Those rows never matched anything at run time — `isAllowed()` reads arrays
 * only — so splitting them here is a repair, not just a display convenience:
 * opening such a consumer and saving it migrates the value to the shape the
 * backend actually enforces.
 *
 * @param {string[]|string|null|undefined} value Persisted value.
 *
 * @return {string[]} Trimmed, non-empty entries.
 */
export function normaliseList(value) {
	if (Array.isArray(value)) {
		return value
			.map((entry) => String(entry ?? '').trim())
			.filter(Boolean)
	}
	if (typeof value === 'string') {
		return value
			.split(',')
			.map((entry) => entry.trim())
			.filter(Boolean)
	}
	return []
}

/**
 * Coerce a form value to a positive integer, or `null` when it is not one.
 *
 * `null` (not `0`, not `NaN`) is the "unset" signal every limit field uses:
 * the schema constrains `requestsPerWindow`, `windowSeconds` and `quota.limit`
 * to `minimum: 1`, so a `0` would be rejected by validation while `null` is
 * simply absent. Returns `null` rather than clamping, so a typo surfaces as an
 * empty field instead of silently becoming `1`.
 *
 * @param {*} value Raw input value.
 *
 * @return {number|null} A positive integer, or null.
 */
export function positiveIntOrNull(value) {
	if (value === null || value === undefined || value === '') return null
	const parsed = Number(value)
	if (!Number.isFinite(parsed)) return null
	const rounded = Math.trunc(parsed)
	return rounded >= 1 ? rounded : null
}

/**
 * Seed a draft from a persisted consumer row.
 *
 * `authorizationConfiguration` is deliberately NOT read: it is
 * `writeOnly: true` (`register.d/99-consumer-secrets-writeonly.json`), so
 * OpenRegister strips it from every API response and the value simply is not
 * in `item` to seed from. The editor therefore always opens blank on edit —
 * see `buildConsumerPayload()` for why that does not destroy the stored
 * credential.
 *
 * @param {object|null} item The row being edited, or null in create mode.
 *
 * @return {object} A draft seeded from the row.
 */
export function consumerDraftFromItem(item) {
	const draft = emptyConsumerDraft()
	if (!item || typeof item !== 'object') return draft

	const rateLimit = (item.rateLimit && typeof item.rateLimit === 'object') ? item.rateLimit : {}
	const quota = (item.quota && typeof item.quota === 'object') ? item.quota : {}

	return {
		...draft,
		name: String(item.name ?? ''),
		description: String(item.description ?? ''),
		domains: normaliseList(item.domains),
		ips: normaliseList(item.ips),
		// An empty stored type means "no authentication" to EndpointService
		// (`$authType === 'none' || $authType === ''`), so it seeds as `none`
		// rather than leaving the picker blank. An off-list stored value is
		// kept verbatim so saving an unrelated field cannot silently rewrite it.
		authorizationType: String(item.authorizationType ?? '') || 'none',
		rateLimitRequestsPerWindow: positiveIntOrNull(rateLimit.requestsPerWindow),
		rateLimitWindowSeconds: positiveIntOrNull(rateLimit.windowSeconds),
		quotaLimit: positiveIntOrNull(quota.limit),
		quotaPeriod: QUOTA_PERIODS.includes(quota.period) ? quota.period : null,
	}
}

/**
 * Reassemble a rate-limit block from its two draft leaves.
 *
 * Both halves are required for a meaningful limiter — `applyRateLimitDecision`
 * counts `requestsPerWindow` hits per `windowSeconds` — so a half-filled pair
 * yields `null` (unlimited) rather than a block the backend would have to
 * guess at.
 *
 * @param {number|null} requestsPerWindow Requests allowed per window.
 * @param {number|null} windowSeconds     Window length in seconds.
 *
 * @return {object|null} The rateLimit block, or null when unlimited.
 */
export function buildRateLimit(requestsPerWindow, windowSeconds) {
	const requests = positiveIntOrNull(requestsPerWindow)
	const seconds = positiveIntOrNull(windowSeconds)
	if (requests === null || seconds === null) return null
	return { requestsPerWindow: requests, windowSeconds: seconds }
}

/**
 * Reassemble a quota block from its two draft leaves.
 *
 * @param {number|null} limit  Requests allowed per period.
 * @param {string|null} period One of QUOTA_PERIODS.
 *
 * @return {object|null} The quota block, or null when unlimited.
 */
export function buildQuota(limit, period) {
	const max = positiveIntOrNull(limit)
	if (max === null || !QUOTA_PERIODS.includes(period)) return null
	return { limit: max, period }
}

/**
 * Build the payload handed to CnIndexPage's `confirm` binding.
 *
 * Three omission rules carry the whole reason this modal exists. All three turn
 * on OpenRegister's PUT contract — an OMITTED schema property is nulled by
 * `SaveObject::fillMissingSchemaPropertiesWithNull()`, except for a write-only
 * one, which `PropertyRbacHandler::collectOmittedWriteOnlyPaths()` restores
 * from storage instead. "Omit to keep, send null to clear" for secrets; "omit
 * to clear" for everything else.
 *
 *  1. **An empty `domains`/`ips` is omitted, never sent as `[]`.** Absent nulls
 *     the property, which `isAllowed()` reads as unrestricted; `[]` would read
 *     as an allowlist admitting nobody and 403 every request. Clearing the last
 *     chip therefore means "stop restricting", which is what clearing an
 *     allowlist reads as to an operator. The `[]` state stays reachable through
 *     the API for anyone who genuinely wants to deny everything — it is just
 *     not something a UI should be able to author by accident, which is exactly
 *     what the generic dialog did.
 *
 *  2. **A blank `authorizationConfiguration` is omitted.** The field always
 *     opens blank on edit (write-only, never returned), so sending its blank
 *     value would wipe the stored credential on every unrelated edit — the
 *     openconnector#245 bug that put the preserve rule in OpenRegister in the
 *     first place. Omitting means an untouched credential survives, and the
 *     operator clears one by sending an explicit `null`, which is what the
 *     "Clear credential" control does.
 *
 *  3. **An unset `rateLimit`/`quota` is sent as an explicit `null`.** Unlike the
 *     allowlist there is no ambiguity to protect — absent and null both mean
 *     unlimited — and an explicit null is what actually removes a previously
 *     configured limiter on PUT.
 *
 * `item` is spread underneath so server-managed keys the draft does not carry
 * (`id`, `uuid`, `created`, `updated`, `userId`, …) survive the round trip;
 * `id` in particular is what makes the store choose PUT over POST.
 *
 * @param {object|null}      item              The row being edited, or null on create.
 * @param {object}           draft             The current draft.
 * @param {object|null}      authConfig        Parsed credential object, `null` to clear, `undefined` to leave untouched.
 *
 * @return {object} The payload to persist.
 */
export function buildConsumerPayload(item, draft, authConfig) {
	const payload = {
		...(item || {}),
		name: String(draft.name ?? '').trim(),
		description: String(draft.description ?? '').trim(),
		authorizationType: draft.authorizationType || 'none',
		rateLimit: buildRateLimit(draft.rateLimitRequestsPerWindow, draft.rateLimitWindowSeconds),
		quota: buildQuota(draft.quotaLimit, draft.quotaPeriod),
	}

	// Rule 1 — omit an empty allowlist rather than sending `[]`.
	const domains = normaliseList(draft.domains)
	const ips = normaliseList(draft.ips)
	if (domains.length > 0) {
		payload.domains = domains
	} else {
		delete payload.domains
	}
	if (ips.length > 0) {
		payload.ips = ips
	} else {
		delete payload.ips
	}

	// Rule 2 — `undefined` means the operator did not touch the credential, so
	// the key must not appear at all. An explicit `null` clears it.
	if (authConfig === undefined) {
		delete payload.authorizationConfiguration
	} else {
		payload.authorizationConfiguration = authConfig
	}

	// An authorization type that carries no credential cannot have one. Sending
	// null (rather than omitting) means switching a consumer to `none` actually
	// retires its stored key instead of leaving an unreachable credential at
	// rest — the generic dialog's conditional-visibility path deletes the key,
	// which the preserve rule then restores.
	//
	// `carriesCredential()` and not a membership test against the offered list:
	// only `none` (and the empty type that normalises to it above) may reach
	// this, never merely an unrecognised value. See that function for the wipe an
	// allow-list caused here.
	if (!carriesCredential(payload.authorizationType)) {
		payload.authorizationConfiguration = null
	}

	return payload
}
