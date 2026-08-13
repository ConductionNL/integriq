/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Consumer editor helpers (src/modals/v2/consumerDraft.js)
 * and for the cross-file consistency the Consumers surfaces depend on.
 *
 * `buildConsumerPayload` carries more weight than a typical helper: its three
 * omission rules are the only thing standing between the form and two silent
 * data defects, and neither is visible at save time.
 *
 *   1. Sending `domains: []` / `ips: []` makes ConsumerScopeService::isAllowed()
 *      read the consumer as having an allowlist that admits nobody, so every
 *      inbound call 403s. That is what the generic CnFormDialog would have done
 *      on every create (initFormData seeds `tags` fields to `[]`).
 *   2. Sending a blank `authorizationConfiguration` wipes the stored credential,
 *      because the field is write-only and therefore ALWAYS opens blank on edit.
 *      This is openconnector#245's shape.
 *
 * `src/modals/v2/**` is also silently unlinted — eslint.config.js intends to
 * un-ignore it with `!src/modals/v2/**`, but `eslint src` prunes the
 * `src/modals` directory before the negation can match — so this suite is the
 * only automated check over that code.
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, it, expect } from 'vitest'
import {
	AUTHORIZATION_TYPES,
	CREDENTIALLESS_AUTHORIZATION_TYPES,
	QUOTA_PERIODS,
	buildConsumerPayload,
	buildQuota,
	buildRateLimit,
	carriesCredential,
	consumerDraftFromItem,
	emptyConsumerDraft,
	normaliseList,
	positiveIntOrNull,
} from '../../src/modals/v2/consumerDraft.js'

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')

/** A draft with a valid name and nothing else set. */
function namedDraft(overrides = {}) {
	return { ...emptyConsumerDraft(), name: 'Partner portal', ...overrides }
}

describe('normaliseList', () => {
	it('trims entries and drops the empty ones', () => {
		expect(normaliseList([' example.com ', '', '  ', 'example.org'])).toEqual([
			'example.com',
			'example.org',
		])
	})

	it('splits a legacy comma-joined string', () => {
		// Rows written by the pre-cutover EditConsumer.vue persisted a textarea
		// verbatim. Those values never matched anything (isAllowed reads arrays
		// only), so splitting them here repairs the row on next save.
		expect(normaliseList('example.com, *.example.org ,10.0.0.1')).toEqual([
			'example.com',
			'*.example.org',
			'10.0.0.1',
		])
	})

	it('treats null, undefined and non-list scalars as empty', () => {
		expect(normaliseList(null)).toEqual([])
		expect(normaliseList(undefined)).toEqual([])
		expect(normaliseList(42)).toEqual([])
	})

	it('returns a new array rather than the input', () => {
		const input = ['example.com']
		expect(normaliseList(input)).not.toBe(input)
	})
})

describe('positiveIntOrNull', () => {
	it('accepts positive integers as numbers and as strings', () => {
		expect(positiveIntOrNull(60)).toBe(60)
		expect(positiveIntOrNull('60')).toBe(60)
	})

	it('truncates rather than rounds', () => {
		expect(positiveIntOrNull('60.9')).toBe(60)
	})

	it('rejects zero and negatives — the schema constrains these to minimum 1', () => {
		expect(positiveIntOrNull(0)).toBeNull()
		expect(positiveIntOrNull('0')).toBeNull()
		expect(positiveIntOrNull(-5)).toBeNull()
		// 0.5 truncates to 0, which is below the minimum.
		expect(positiveIntOrNull('0.5')).toBeNull()
	})

	it('treats empty, null, undefined and non-numeric input as unset', () => {
		expect(positiveIntOrNull('')).toBeNull()
		expect(positiveIntOrNull(null)).toBeNull()
		expect(positiveIntOrNull(undefined)).toBeNull()
		expect(positiveIntOrNull('abc')).toBeNull()
		expect(positiveIntOrNull(Infinity)).toBeNull()
	})
})

describe('buildRateLimit / buildQuota', () => {
	it('builds a block when both halves are present', () => {
		expect(buildRateLimit(60, 60)).toEqual({
			requestsPerWindow: 60,
			windowSeconds: 60,
		})
		expect(buildQuota(10000, 'day')).toEqual({ limit: 10000, period: 'day' })
	})

	it('yields null when either half is missing', () => {
		// A limiter needs both numbers to mean anything, so a half-filled pair is
		// unlimited rather than a block the backend has to guess at.
		expect(buildRateLimit(60, null)).toBeNull()
		expect(buildRateLimit(null, 60)).toBeNull()
		expect(buildQuota(10000, null)).toBeNull()
		expect(buildQuota(null, 'day')).toBeNull()
	})

	it('rejects a quota period that is not in the schema enum', () => {
		expect(buildQuota(10, 'week')).toBeNull()
	})
})

describe('consumerDraftFromItem', () => {
	it('returns an empty draft for create mode', () => {
		expect(consumerDraftFromItem(null)).toEqual(emptyConsumerDraft())
		expect(consumerDraftFromItem(undefined).authorizationType).toBe('none')
	})

	it('flattens the two nested limit blocks into scalars', () => {
		const draft = consumerDraftFromItem({
			name: 'Partner',
			rateLimit: { requestsPerWindow: 2, windowSeconds: 60 },
			quota: { limit: 100, period: 'day' },
		})
		expect(draft.rateLimitRequestsPerWindow).toBe(2)
		expect(draft.rateLimitWindowSeconds).toBe(60)
		expect(draft.quotaLimit).toBe(100)
		expect(draft.quotaPeriod).toBe('day')
	})

	it('seeds an absent or empty authorizationType as none', () => {
		// EndpointService treats '' and 'none' identically
		// (`$authType === 'none' || $authType === ''`), so a blank stored value
		// is shown as what it behaves like rather than as an unset picker.
		expect(consumerDraftFromItem({ name: 'x' }).authorizationType).toBe('none')
		expect(
			consumerDraftFromItem({ name: 'x', authorizationType: '' })
				.authorizationType,
		).toBe('none')
	})

	it('keeps an off-list authorizationType verbatim', () => {
		// Saving an unrelated field must never silently rewrite a stored value
		// the picker does not happen to offer.
		expect(
			consumerDraftFromItem({ name: 'x', authorizationType: 'mtls' })
				.authorizationType,
		).toBe('mtls')
	})

	it('never seeds the write-only credential', () => {
		// It is not in the response to seed from, and reading one would mean the
		// writeOnly render boundary had been breached.
		const draft = consumerDraftFromItem({
			name: 'x',
			authorizationConfiguration: { apiKey: 'leaked' },
		})
		expect(draft.authorizationConfiguration).toBeUndefined()
	})

	it('ignores a non-object rateLimit or quota instead of throwing', () => {
		const draft = consumerDraftFromItem({
			name: 'x',
			rateLimit: 'nonsense',
			quota: null,
		})
		expect(draft.rateLimitRequestsPerWindow).toBeNull()
		expect(draft.quotaLimit).toBeNull()
	})
})

describe('buildConsumerPayload — allowlist omission (REQ-CON-SCOPE-001)', () => {
	it('OMITS an empty allowlist rather than sending []', () => {
		// The defect this whole modal exists to prevent. `[]` would make
		// isAllowed() 403 every inbound request; an omitted key is nulled on PUT,
		// which reads as unrestricted.
		const payload = buildConsumerPayload(null, namedDraft(), undefined)
		expect('domains' in payload).toBe(false)
		expect('ips' in payload).toBe(false)
	})

	it('omits an allowlist whose entries are all blank', () => {
		const payload = buildConsumerPayload(
			null,
			namedDraft({ domains: ['', '  '] }),
			undefined,
		)
		expect('domains' in payload).toBe(false)
	})

	it('sends a populated allowlist as a real array', () => {
		const payload = buildConsumerPayload(
			null,
			namedDraft({ domains: [' example.com '], ips: ['10.0.0.0/8'] }),
			undefined,
		)
		expect(payload.domains).toEqual(['example.com'])
		expect(payload.ips).toEqual(['10.0.0.0/8'])
	})

	it('drops a previously stored allowlist when the last entry is removed', () => {
		// The key must be absent, not `[]` — otherwise "I removed the domains"
		// would silently mean "reject everyone" instead of "stop restricting".
		const item = { id: 7, domains: ['old.example.com'], ips: ['10.0.0.1'] }
		const payload = buildConsumerPayload(item, namedDraft(), undefined)
		expect('domains' in payload).toBe(false)
		expect('ips' in payload).toBe(false)
	})
})

describe('buildConsumerPayload — write-only credential (openconnector#245)', () => {
	it('OMITS the credential when it was not touched', () => {
		// undefined = "the operator typed nothing". OpenRegister's
		// collectOmittedWriteOnlyPaths() then carries the stored value forward.
		const payload = buildConsumerPayload(
			{ id: 7, authorizationType: 'apiKey' },
			namedDraft({ authorizationType: 'apiKey' }),
			undefined,
		)
		expect('authorizationConfiguration' in payload).toBe(false)
	})

	it('sends an explicit null to clear the credential', () => {
		const payload = buildConsumerPayload(
			{ id: 7 },
			namedDraft({ authorizationType: 'apiKey' }),
			null,
		)
		expect(payload.authorizationConfiguration).toBeNull()
	})

	it('sends a typed credential through unchanged', () => {
		const payload = buildConsumerPayload(
			null,
			namedDraft({ authorizationType: 'apiKey' }),
			{ apiKey: 's3cr3t' },
		)
		expect(payload.authorizationConfiguration).toEqual({ apiKey: 's3cr3t' })
	})

	it('nulls the credential for an authorization type that carries none', () => {
		// Switching to `none` must actually retire the key. The generic dialog's
		// conditional-visibility path DELETES the form key instead, which the
		// preserve rule then restores — leaving an unreachable credential at rest.
		const payload = buildConsumerPayload(
			{ id: 7 },
			namedDraft({ authorizationType: 'none' }),
			{ apiKey: 'typed' },
		)
		expect(payload.authorizationConfiguration).toBeNull()
	})

	it('KEEPS the credential of a lowercase `apikey` consumer edited elsewhere', () => {
		// The regression the deny-list exists for. `resolveConsumerByApiKey()`
		// matches case-insensitively, so `apikey` authenticates; the old
		// allow-list membership test did not, so it hid the credential editor AND
		// nulled the property — silently retiring a working key when the operator
		// had only touched, say, the description.
		const item = { id: 7, authorizationType: 'apikey', description: 'old' }
		const draft = namedDraft({ authorizationType: 'apikey', description: 'new' })
		const payload = buildConsumerPayload(item, draft, undefined)
		expect('authorizationConfiguration' in payload).toBe(false)
		expect(payload.authorizationType).toBe('apikey')
	})

	it('KEEPS the credential of a consumer whose type is not on the offered list', () => {
		// Not only a casing problem: `findIssuer()` filters on the consumer's name
		// alone and reads `authorizationConfiguration.publicKey` whatever the type
		// says, so an off-list value can sit on a working JWT issuer. An
		// unrecognised type must fail safe.
		const payload = buildConsumerPayload(
			{ id: 7, authorizationType: 'Jwt' },
			namedDraft({ authorizationType: 'Jwt' }),
			undefined,
		)
		expect('authorizationConfiguration' in payload).toBe(false)
	})

	it('still clears a lowercase `apikey` credential on an explicit request', () => {
		// Failing safe must not cost the operator the Clear button — which now
		// renders for these consumers, since `carriesCredential` is the same
		// predicate the nulling rule uses.
		const payload = buildConsumerPayload(
			{ id: 7, authorizationType: 'apikey' },
			namedDraft({ authorizationType: 'apikey' }),
			null,
		)
		expect(payload.authorizationConfiguration).toBeNull()
	})
})

describe('carriesCredential', () => {
	it('classifies casing variants by the same rule the engine uses', () => {
		for (const type of ['apiKey', 'apikey', 'APIKEY', 'Jwt', 'basic', 'saml']) {
			expect(carriesCredential(type), type).toBe(true)
		}
	})

	it('treats only `none` and an absent type as credential-free', () => {
		for (const type of ['none', 'None', 'NONE', ' none ', '', null, undefined]) {
			expect(carriesCredential(type), String(type)).toBe(false)
		}
	})

	it('gates the editor on exactly the types whose credential a save nulls', () => {
		// The two used to be separate lists, and the drift between them was
		// invisible BECAUSE the hidden editor was what hid it. Pin them together:
		// anything the editor hides, the payload may null; anything it shows, it
		// may not.
		for (const type of [...AUTHORIZATION_TYPES, 'apikey', 'Jwt', 'saml', '']) {
			const payload = buildConsumerPayload(
				{ id: 7 },
				namedDraft({ authorizationType: type }),
				undefined,
			)
			const nulled = payload.authorizationConfiguration === null
			expect(
				nulled,
				`${type || '(empty)'} — editor hidden must equal credential nulled`,
			).toBe(!carriesCredential(payload.authorizationType))
		}
	})
})

describe('buildConsumerPayload — limits and identity', () => {
	it('nulls both limit blocks when unset, so a PUT clears a previous limiter', () => {
		const payload = buildConsumerPayload({ id: 7 }, namedDraft(), undefined)
		expect(payload.rateLimit).toBeNull()
		expect(payload.quota).toBeNull()
	})

	it('reassembles both limit blocks from the draft scalars', () => {
		const payload = buildConsumerPayload(
			null,
			namedDraft({
				rateLimitRequestsPerWindow: 2,
				rateLimitWindowSeconds: 60,
				quotaLimit: 100,
				quotaPeriod: 'day',
			}),
			undefined,
		)
		expect(payload.rateLimit).toEqual({
			requestsPerWindow: 2,
			windowSeconds: 60,
		})
		expect(payload.quota).toEqual({ limit: 100, period: 'day' })
	})

	it('trims name and description', () => {
		const payload = buildConsumerPayload(
			null,
			namedDraft({ name: '  Partner  ', description: ' notes ' }),
			undefined,
		)
		expect(payload.name).toBe('Partner')
		expect(payload.description).toBe('notes')
	})

	it('preserves server-managed keys from the edited row', () => {
		// `id` in particular is what makes the store choose PUT over POST.
		const item = {
			id: 7,
			uuid: 'abc',
			created: '2026-01-01T00:00:00+00:00',
			userId: 'admin',
		}
		const payload = buildConsumerPayload(item, namedDraft(), undefined)
		expect(payload.id).toBe(7)
		expect(payload.uuid).toBe('abc')
		expect(payload.created).toBe('2026-01-01T00:00:00+00:00')
		expect(payload.userId).toBe('admin')
	})

	it('does not leak the flattened draft scalars into the payload', () => {
		// They are form state, not schema properties — OpenRegister would store
		// them as unknown keys on the object.
		const payload = buildConsumerPayload(
			null,
			namedDraft({
				rateLimitRequestsPerWindow: 2,
				rateLimitWindowSeconds: 60,
			}),
			undefined,
		)
		for (const key of [
			'rateLimitRequestsPerWindow',
			'rateLimitWindowSeconds',
			'quotaLimit',
			'quotaPeriod',
		]) {
			expect(key in payload).toBe(false)
		}
	})
})

describe('Consumer form configuration consistency', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.join(REPO_ROOT, 'src/manifest.json'), 'utf8'),
	)
	const consumersPage = manifest.pages.find((page) => page.id === 'Consumers')
	const detailPage = manifest.pages.find((page) => page.id === 'ConsumerDetail')
	const register = JSON.parse(
		fs.readFileSync(
			path.join(REPO_ROOT, 'lib/Settings/openconnector_register.json'),
			'utf8',
		),
	)
	const fragment = JSON.parse(
		fs.readFileSync(
			path.join(
				REPO_ROOT,
				'lib/Settings/register.d/consumer-form-fields.json',
			),
			'utf8',
		),
	)
	const writeOnlyFragment = JSON.parse(
		fs.readFileSync(
			path.join(
				REPO_ROOT,
				'lib/Settings/register.d/99-consumer-secrets-writeonly.json',
			),
			'utf8',
		),
	)
	const baseProps = register.components.schemas.consumer.properties
	const fragmentProps = fragment.components.schemas.consumer.properties

	it('wires the Consumers page to ConsumerEditorModal', () => {
		expect(consumersPage.slots['form-dialog']).toBe('ConsumerEditorModal')
	})

	it('registers that component name in the custom-component registry', () => {
		// CnPageRenderer resolves slot values against registry.js; a name that is
		// not exported there renders nothing at all, with no error.
		const registry = fs.readFileSync(
			path.join(REPO_ROOT, 'src/registry.js'),
			'utf8',
		)
		expect(registry).toContain(
			"import ConsumerEditorModal from './modals/v2/ConsumerEditorModal.vue'",
		)
		expect(registry).toMatch(/^\tConsumerEditorModal,$/m)
	})

	it('carries no includeFields or fieldOverrides, which would be dead config', () => {
		// CnFormDialog never mounts once `form-dialog` is slotted, so either key
		// would read as if it governed the form while doing nothing. The three
		// other form-dialog pages (Mappings, Rules, Synchronizations) carry
		// neither, and this is that convention pinned.
		expect('includeFields' in consumersPage.config).toBe(false)
		expect('fieldOverrides' in consumersPage.config).toBe(false)
	})

	it('declares an order for every property the editor authors', () => {
		const authored = [
			'name',
			'description',
			'domains',
			'ips',
			'authorizationType',
			'authorizationConfiguration',
			'rateLimit',
			'quota',
		]
		for (const key of authored) {
			expect(
				baseProps,
				`${key} must exist on the consumer schema`,
			).toHaveProperty(key)
			expect(
				typeof fragmentProps[key]?.order,
				`${key} must declare a numeric order`,
			).toBe('number')
		}
	})

	it('gives every object-typed property an explicit widget', () => {
		// fieldsFromSchema drops `type: object` props that carry no widget, and
		// tests prop.widget — the schema's — before overrides merge, so without
		// this they render on no schema-driven surface at all. This is the
		// assertion whose absence left the Jobs page's `arguments` override inert.
		for (const [key, prop] of Object.entries(baseProps)) {
			if (prop.type !== 'object') continue
			expect(
				fragmentProps[key]?.widget,
				`${key} is type: object and needs a widget`,
			).toBe('json')
		}
	})

	it('uses unique order values', () => {
		const orders = Object.values(fragmentProps).map((prop) => prop.order)
		expect(new Set(orders).size).toBe(orders.length)
	})

	it('orders the fields the way the editor lays them out', () => {
		const byOrder = Object.entries(fragmentProps)
			.sort(([, a], [, b]) => a.order - b.order)
			.map(([key]) => key)
		expect(byOrder).toEqual([
			'name',
			'description',
			'domains',
			'ips',
			'authorizationType',
			'authorizationConfiguration',
			'rateLimit',
			'quota',
		])
	})

	it('bumps the schema version so OpenRegister re-imports', () => {
		// importFromApp is version-gated; without a bump it falls back to a
		// content-differs comparison rather than taking the fast path.
		const base = register.components.schemas.consumer.version
		expect(fragment.components.schemas.consumer.version).not.toBe(base)
	})

	it('does not clobber the write-only flag on the credential', () => {
		// This fragment sorts after 99-consumer-secrets-writeonly.json, and
		// deepMergeConfig recurses object+object, so `widget` should land beside
		// `writeOnly` rather than replacing the property. If this fragment ever
		// declared writeOnly itself, or the merge order flipped, the credential
		// would start being returned in cleartext.
		expect(
			writeOnlyFragment.components.schemas.consumer.properties
				.authorizationConfiguration.writeOnly,
		).toBe(true)
		expect('writeOnly' in fragmentProps.authorizationConfiguration).toBe(false)
		const fragmentNames = fs
			.readdirSync(path.join(REPO_ROOT, 'lib/Settings/register.d'))
			.filter((file) => file.endsWith('.json'))
			.sort()
		expect(fragmentNames.indexOf('consumer-form-fields.json')).toBeGreaterThan(
			fragmentNames.indexOf('99-consumer-secrets-writeonly.json'),
		)
	})

	it('keeps the quota periods in step with the schema enum', () => {
		expect([...QUOTA_PERIODS].sort()).toEqual(
			[...baseProps.quota.properties.period.enum].sort(),
		)
	})

	it('offers no authorizationType enum on the schema', () => {
		// An enum is enforced on save, so a legitimate casing variant would become
		// unsaveable while still authenticating. The list is presentational. (Not
		// the seeded consumers: they declare no authorizationType, and validation
		// runs before the absent property is null-filled.)
		expect(baseProps.authorizationType.enum).toBeUndefined()
		expect(fragmentProps.authorizationType.enum).toBeUndefined()
	})

	it('offers the type the app itself writes, and the one the engine special-cases', () => {
		// NotificatiesSubscriberService::provisionConsumer() writes `apiKey`, and
		// EndpointService keys the limiter on IP for `none`. Both must be
		// selectable or the picker cannot round-trip app-created rows.
		expect(AUTHORIZATION_TYPES).toContain('apiKey')
		expect(AUTHORIZATION_TYPES).toContain('none')
	})

	it('treats every offered type but `none` as credential-bearing', () => {
		// The deny-list and the offered list have to agree on the types they both
		// know about; they are only allowed to differ on the ones neither lists.
		for (const type of AUTHORIZATION_TYPES) {
			expect(carriesCredential(type), `${type} classification`).toBe(
				type !== 'none',
			)
		}
		expect(CREDENTIALLESS_AUTHORIZATION_TYPES).toContain('none')
	})

	it('has a translated label for every offered type and period', () => {
		// The label maps live in the SFC (not the manifest) so the strings stay
		// extractable by tests/l10n/check-l10n.js. The SFC is read as text
		// because vitest runs without the Vue SFC plugin.
		const sfc = fs.readFileSync(
			path.join(REPO_ROOT, 'src/modals/v2/ConsumerEditorModal.vue'),
			'utf8',
		)
		for (const id of [...AUTHORIZATION_TYPES, ...QUOTA_PERIODS]) {
			expect(sfc, `${id} needs a t() label`).toContain(`${id}: t(`)
		}
	})

	it('excludes only the unrenderable write-only field from the detail data widget', () => {
		// rateLimit and quota SHOULD show there — the panel confirms the access
		// policy. authorizationConfiguration is stripped from every response, so
		// it could only ever be a permanently empty row.
		const dataWidget = detailPage.config.widgets.find(
			(widget) => widget.id === 'con-data',
		)
		expect(dataWidget.content.exclude).toEqual(['authorizationConfiguration'])
	})
})
