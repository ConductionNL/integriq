/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Builds the `authentication` block of a rule's save payload.
 *
 * SECURITY (ocon#147 / openregister#459 / openregister#463): an authentication-type
 * rule stores its inbound API keys at `configuration.authentication.keys` — a map of
 * apiKey => nextcloud userId, i.e. a set of live impersonation credentials. As of
 * ocon#147's last residual those keys are declared WRITE-ONLY
 * (99-rule-nested-auth-writeonly.json), so EVERY rendered read strips them (admins
 * included). Consequences for this editor:
 *
 *   1. The editor NEVER receives the stored keys, so `apiKeys` seeds empty on open.
 *   2. openregister#463 preserves an OMITTED write-only value on save: when the incoming
 *      payload does not carry `configuration.authentication.keys`, OpenRegister carries the
 *      stored keys forward instead of nulling them.
 *   3. Sending `keys: []` (the empty seeded UI) would be a PRESENT value, NOT an omission —
 *      OpenRegister's PUT-semantic null-fill would then DESTROY every stored key.
 *
 * Therefore this helper OMITS the `keys` property entirely whenever the operator entered no
 * complete new key (blank apiKey or no selected user = incomplete), and only emits `keys`
 * when at least one complete new key was actually typed. Omit == "keep the stored keys";
 * a non-empty `keys` == "replace the stored keys with exactly these".
 *
 * A LIVE UI round-trip (edit a rule, save without touching apiKeys, confirm the stored keys
 * still authenticate) is the recommended final check before trusting this in production.
 *
 * @param {object}                 params
 * @param {string}                 params.type    Authentication type value (e.g. 'basic', 'api-key').
 * @param {Array<string>}          params.users   Selected user ids.
 * @param {Array<string>}          params.groups  Selected group values.
 * @param {Array<object>}          [params.apiKeys] apiKeys UI rows, shape `[{ apiKey, user: { id } }]`.
 * @return {object} The authentication config. `keys` is ABSENT when no complete new key was entered.
 */
export function buildAuthenticationConfiguration({ type, users, groups, apiKeys = [] }) {
	const authentication = {
		type,
		users,
		groups,
	}

	// A "complete new key" has both a non-empty apiKey string AND a selected user id.
	// Incomplete rows (the empty seed row, or a half-filled row) are not new keys.
	const enteredKeys = (apiKeys || [])
		.filter(key => key.apiKey && key.user?.id)
		.map(key => ({ [key.apiKey]: key.user.id }))

	// LOAD-BEARING OMIT: only attach `keys` when the operator actually entered new ones.
	// When empty, leaving `keys` off the payload lets openregister#463 preserve the stored
	// (hidden, write-only) keys. Attaching `keys: []` here would DESTROY them on save.
	if (enteredKeys.length > 0) {
		authentication.keys = enteredKeys
	}

	return authentication
}
