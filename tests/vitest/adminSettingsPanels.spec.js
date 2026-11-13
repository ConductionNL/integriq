/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings panels without a spec of their own, or with only their
 * save recorded: the digital post account, the webhook connections list, the
 * action authorization matrix and the Berichtenbox connection. Each runs its
 * own mounted hook, computed properties and methods against a stubbed axios,
 * router and toast, so what it loads, shows and sends is asserted exactly.
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = { calls: [], answers: [] }
const toasts = []

/**
 * Record a call and answer with the next queued answer; an `{ reject }` entry rejects.
 *
 * @param {string} method the HTTP verb
 * @param {Array} args the axios arguments
 * @return {Promise<object>} the answer
 */
function answer(method, args) {
	http.calls.push([method, ...args])
	const next = http.answers.shift() ?? { data: {} }
	return next.reject ? Promise.reject(next.reject) : Promise.resolve(next)
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => answer('get', args),
		put: (...args) => answer('put', args),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
	showWarning: (text) => toasts.push(['warning', text]),
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url) => `/ocs/v2.php/${url}`,
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (m, key) => params[key]),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcNoteCard: {},
	NcPasswordField: {},
	NcSelect: {},
	NcSelectUsers: {},
	NcTextField: {},
}))

const DigitalPostAccountSettings = (await import('@/views/admin/DigitalPostAccountSettings.vue')).default
const WebhookConnectionsSettings = (await import('@/views/admin/WebhookConnectionsSettings.vue')).default
const ActionAuthMatrix = (await import('@/views/admin/ActionAuthMatrix.vue')).default
const BerichtenboxSettings = (await import('@/views/admin/BerichtenboxSettings.vue')).default

/**
 * A component's `this`: its data, bound methods and computed getters, with a
 * translation stand-in that fills `{name}` placeholders.
 *
 * @param {object} component the component options
 * @return {object} the context
 */
function context(component) {
	const ctx = {
		t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m)),
	}
	Object.assign(ctx, component.data ? component.data.call(ctx) : {})
	for (const [name, fn] of Object.entries(component.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(component.computed || {})) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx), configurable: true })
	}
	return ctx
}

/**
 * An axios-shaped error with a response.
 *
 * @param {number} status the status
 * @param {object} data the body
 * @return {{reject: Error}} the queued rejection
 */
function refused(status, data) {
	return { reject: Object.assign(new Error(`status ${status}`), { response: { status, data } }) }
}

beforeEach(() => {
	http.calls = []
	http.answers = []
	toasts.length = 0
})

describe('DigitalPostAccountSettings', () => {
	it('loads the stored account and preselects it', async () => {
		const vm = context(DigitalPostAccountSettings)
		http.answers = [{ data: { account: { state: 'ok', userId: 'post-bot', displayName: 'Post bot' } } }]
		await DigitalPostAccountSettings.mounted.call(vm)
		expect(http.calls).toEqual([['get', '/apps/integriq/api/admin/digital-post-account']])
		expect(vm.selected).toEqual({ id: 'post-bot', user: 'post-bot', displayName: 'Post bot' })
		expect(vm.state).toEqual({ refused: false, text: 'Digital post is stored as Post bot' })
		expect(vm.loading).toBe(false)
	})

	it('says digital post is refused without an account or with an unusable one', () => {
		const vm = context(DigitalPostAccountSettings)
		expect(vm.state).toEqual({ refused: true, text: 'No account set: digital post is refused' })
		vm.apply({ state: 'disabled', userId: 'gone' })
		expect(vm.selected).toEqual({ id: 'gone', user: 'gone', displayName: 'gone' })
		expect(vm.state).toEqual({ refused: true, text: 'Account gone is not usable: digital post is refused' })
		vm.apply({ state: 'none', userId: '' })
		expect(vm.selected).toBeNull()
	})

	it('a failed load says so', async () => {
		const vm = context(DigitalPostAccountSettings)
		http.answers = [refused(500, {})]
		await DigitalPostAccountSettings.mounted.call(vm)
		expect(vm.accountError).toBe('Failed to load the digital post account.')
		expect(vm.loading).toBe(false)
	})

	it('searches accounts from two characters on', async () => {
		const vm = context(DigitalPostAccountSettings)
		await vm.search('a')
		await vm.search('')
		expect(http.calls).toEqual([])
		http.answers = [{ data: { ocs: { data: [{ id: 'anna', label: 'Anna' }, { id: 'an2' }] } } }]
		await vm.search('an')
		expect(http.calls).toEqual([['get', '/ocs/v2.php/core/autocomplete/get', {
			params: { search: 'an', itemType: '', itemId: '', shareTypes: [0], limit: 10 },
		}]])
		expect(vm.accountOptions).toEqual([
			{ id: 'anna', user: 'anna', displayName: 'Anna' },
			{ id: 'an2', user: 'an2', displayName: 'an2' },
		])
		expect(vm.searching).toBe(false)
	})

	it('a failed search says so', async () => {
		const vm = context(DigitalPostAccountSettings)
		http.answers = [refused(500, {})]
		await vm.search('anna')
		expect(vm.accountError).toBe('Searching accounts failed.')
		expect(vm.searching).toBe(false)
	})

	it('saves the picked account, or none, and shows the warnings', async () => {
		const vm = context(DigitalPostAccountSettings)
		vm.selected = { id: 'anna' }
		http.answers = [{ data: { account: { state: 'ok', userId: 'anna', displayName: 'Anna' }, warnings: ['Anna has no mail address.'] } }]
		await vm.save()
		expect(http.calls).toEqual([['put', '/apps/integriq/api/admin/digital-post-account', { userId: 'anna' }]])
		expect(toasts).toEqual([['success', 'Digital post account saved.'], ['warning', 'Anna has no mail address.']])
		expect(vm.saving).toBe(false)

		vm.selected = null
		await vm.save()
		expect(http.calls[1][2]).toEqual({ userId: '' })
	})

	it('a refused save shows the field error and the server\'s errors', async () => {
		const vm = context(DigitalPostAccountSettings)
		http.answers = [refused(400, { fieldErrors: { userId: 'Unknown account' }, errors: ['Not saved.', 'Pick another.'] })]
		await vm.save()
		expect(vm.accountError).toBe('Unknown account')
		expect(toasts).toEqual([['error', 'Not saved. Pick another.']])

		http.answers = [{ reject: new Error('Network Error') }]
		await vm.save()
		expect(vm.accountError).toBe('')
		expect(toasts.at(-1)).toEqual(['error', 'Failed to save the digital post account.'])
		expect(vm.saving).toBe(false)
	})
})

describe('WebhookConnectionsSettings', () => {
	it('loads the connections', async () => {
		const vm = context(WebhookConnectionsSettings)
		const connections = [{ authorizationType: 'a', label: 'A' }, { authorizationType: 'b', label: 'B' }]
		http.answers = [{ data: { connections } }]
		await WebhookConnectionsSettings.mounted.call(vm)
		expect(http.calls).toEqual([['get', '/apps/integriq/api/admin/webhook-connections']])
		expect(vm.connections).toEqual(connections)
		expect(vm.loading).toBe(false)
	})

	it('reads no connections as none, and a failure says so', async () => {
		const vm = context(WebhookConnectionsSettings)
		http.answers = [{ data: {} }]
		await WebhookConnectionsSettings.mounted.call(vm)
		expect(vm.connections).toEqual([])
		http.answers = [refused(500, {})]
		await WebhookConnectionsSettings.mounted.call(vm)
		expect(vm.error).toBe('Failed to load the webhook connections.')
		expect(vm.loading).toBe(false)
	})

	it('a saved connection replaces the one of its type only', () => {
		const vm = context(WebhookConnectionsSettings)
		vm.connections = [{ authorizationType: 'a', label: 'A' }, { authorizationType: 'b', label: 'B' }]
		vm.replace({ authorizationType: 'b', label: 'B saved' })
		expect(vm.connections).toEqual([{ authorizationType: 'a', label: 'A' }, { authorizationType: 'b', label: 'B saved' }])
	})
})

describe('ActionAuthMatrix', () => {
	it('shows admin first and always on', () => {
		const vm = context(ActionAuthMatrix)
		vm.groups = ['editors', 'admin', 'viewers']
		expect(vm.displayGroups).toEqual(['admin', 'editors', 'viewers'])
		expect(vm.isChecked('rule.edit', 'admin')).toBe(true)
		expect(vm.isChecked('rule.edit', 'editors')).toBe(false)
	})

	it('loads the actions, groups and an editable copy of the matrix', async () => {
		const vm = context(ActionAuthMatrix)
		const matrix = { 'rule.edit': ['editors'] }
		http.answers = [{ data: { actions: ['rule.edit', 'source.delete'], groups: ['editors'], matrix } }]
		await ActionAuthMatrix.mounted.call(vm)
		expect(http.calls).toEqual([['get', '/apps/integriq/api/admin/action-matrix']])
		expect(vm.matrix).toEqual({ 'rule.edit': ['editors'], 'source.delete': [] })
		vm.matrix['rule.edit'].push('x')
		expect(matrix['rule.edit']).toEqual(['editors'])
		expect(vm.loading).toBe(false)
	})

	it('reads a malformed answer as no actions, and a failure says so', async () => {
		const vm = context(ActionAuthMatrix)
		http.answers = [{ data: { actions: 'x', groups: null } }]
		await vm.load()
		expect(vm.actions).toEqual([])
		expect(vm.groups).toEqual([])
		expect(vm.matrix).toEqual({})

		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		http.answers = [refused(500, {})]
		await vm.load()
		error.mockRestore()
		expect(vm.error).toBe('Failed to load the action matrix.')
		expect(vm.loading).toBe(false)
	})

	it('ticking adds a group once, unticking removes it, admin never changes', () => {
		const vm = context(ActionAuthMatrix)
		vm.matrix = { 'rule.edit': ['editors'] }
		vm.toggle('rule.edit', 'viewers', true)
		vm.toggle('rule.edit', 'viewers', true)
		expect(vm.matrix['rule.edit']).toEqual(['editors', 'viewers'])
		vm.toggle('rule.edit', 'editors', false)
		vm.toggle('rule.edit', 'editors', false)
		expect(vm.matrix['rule.edit']).toEqual(['viewers'])
		vm.toggle('source.delete', 'editors', true)
		expect(vm.matrix['source.delete']).toEqual(['editors'])
		const before = vm.matrix
		vm.toggle('rule.edit', 'admin', false)
		expect(vm.matrix).toBe(before)
		expect(vm.isChecked('rule.edit', 'viewers')).toBe(true)
	})

	it('a failed save says so and keeps the edits', async () => {
		const vm = context(ActionAuthMatrix)
		vm.actions = ['rule.edit']
		vm.matrix = { 'rule.edit': ['editors'] }
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		http.answers = [refused(403, {})]
		await vm.save()
		error.mockRestore()
		expect(http.calls[0]).toEqual(['put', '/apps/integriq/api/admin/action-matrix', { matrix: { 'rule.edit': ['admin', 'editors'] } }])
		expect(toasts).toEqual([['error', 'Failed to save the action matrix.']])
		expect(vm.matrix).toEqual({ 'rule.edit': ['editors'] })
		expect(vm.saving).toBe(false)
	})
})

describe('BerichtenboxSettings', () => {
	const SOURCE = {
		senderOin: '00000001234567890000',
		adapterUrl: 'https://ebms.example/service/rest/v19/ebms',
		cpaId: 'cpa-1',
		toPartyId: 'logius',
		berichtTypes: { besluit: 'B01' },
		adapterToken: 'set',
		certificate: { configured: true, usable: true, subject: 'CN=Gemeente', serialNumber: '0000000123', validTo: '2027-05-01T00:00:00Z' },
	}

	it('loads the stored connection into the form', async () => {
		const vm = context(BerichtenboxSettings)
		http.answers = [{ data: { live: true, source: SOURCE } }]
		await BerichtenboxSettings.mounted.call(vm)
		expect(http.calls).toEqual([['get', '/apps/integriq/api/admin/berichtenbox/berichtenbox']])
		expect(vm.live).toBe(true)
		expect(vm.form).toEqual({
			senderOin: '00000001234567890000',
			adapterUrl: 'https://ebms.example/service/rest/v19/ebms',
			cpaId: 'cpa-1',
			fromPartyId: '',
			toPartyId: 'logius',
			service: '',
			wusEndpoint: '',
		})
		expect(vm.berichtTypes).toEqual({ besluit: 'B01', 'case-update': '', statutory: '', service: '' })
		expect(vm.adapterTokenConfigured).toBe(true)
		expect(vm.certificateState).toBe('Stored: CN=Gemeente, OIN 0000000123, valid until 2027-05-01. Paste a new one to replace it.')
		expect(vm.loading).toBe(false)
	})

	it('no source yet leaves the form empty and refuses every letter', async () => {
		const vm = context(BerichtenboxSettings)
		http.answers = [{ data: { live: false, source: null } }]
		await BerichtenboxSettings.mounted.call(vm)
		expect(vm.form.senderOin).toBe('')
		expect(vm.adapterTokenConfigured).toBe(false)
		expect(vm.certificateState).toBe('No certificate stored: every letter is refused.')
	})

	it('an unusable certificate says why, a missing OIN reads as a dash', () => {
		const vm = context(BerichtenboxSettings)
		vm.source = { certificate: { configured: true, usable: false, error: 'expired' } }
		expect(vm.certificateState).toBe('The stored certificate cannot be used: expired')
		vm.source = { certificate: { configured: true, usable: true, subject: 'CN=X' } }
		expect(vm.certificateState).toBe('Stored: CN=X, OIN -, valid until . Paste a new one to replace it.')
	})

	it('a 404 only reads whether the connection is live; another failure says so', async () => {
		const vm = context(BerichtenboxSettings)
		http.answers = [refused(404, { live: true })]
		await BerichtenboxSettings.mounted.call(vm)
		expect(vm.live).toBe(true)
		expect(toasts).toEqual([])

		const other = context(BerichtenboxSettings)
		http.answers = [refused(500, {})]
		await BerichtenboxSettings.mounted.call(other)
		expect(other.live).toBe(false)
		expect(toasts).toEqual([['error', 'Failed to load the Berichtenbox settings.']])
		expect(other.loading).toBe(false)
	})

	it('offers its seven connection fields and four letter categories', () => {
		const vm = context(BerichtenboxSettings)
		expect(vm.fields.map((field) => field.key)).toEqual(['senderOin', 'adapterUrl', 'cpaId', 'fromPartyId', 'toPartyId', 'service', 'wusEndpoint'])
		expect(vm.fields.map((field) => field.key)).toEqual(Object.keys(vm.form))
		expect(vm.categories.map((category) => category.key)).toEqual(Object.keys(vm.berichtTypes))
	})
})
