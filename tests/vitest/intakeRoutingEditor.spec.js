/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The intake routing editor: IntakeRuleEditorModal saves through saveRule()
 * and keeps a refusal on its field, the test sends the draft and a sample,
 * and the row handlers reorder and enable rules through the same endpoint.
 * Components run on a plain context built from their data(), computed and
 * methods; the HTTP client, the toasts and the library signal are mocked.
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const library = vi.hoisted(() => ({ dispatchObjectsChanged: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@conduction/nextcloud-vue', () => library)
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcDialog: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextArea: {},
	NcTextField: {},
}))
vi.mock('@/modals/v2/EditorSaveButton.vue', () => ({ default: {} }))

const editor = (await import('@/modals/v2/IntakeRuleEditorModal.vue')).default
const handlers = await import('@/handlers/intakeRuleHandlers.js')

/**
 * A plain context with a component's props, data, computed getters, mixin computeds and methods.
 *
 * @param {object} component The component definition.
 * @param {object} props The props.
 * @return {object} The context.
 */
function context(component, props = {}) {
	const ctx = { ...props }
	Object.assign(ctx, component.data?.call(ctx) ?? {})
	const methods = { ...(component.mixins || []).reduce((all, mixin) => ({ ...all, ...mixin.methods }), {}), ...component.methods }
	for (const [name, fn] of Object.entries(methods)) {
		ctx[name] = fn.bind(ctx)
	}
	const computed = { ...(component.mixins || []).reduce((all, mixin) => ({ ...all, ...mixin.computed }), {}), ...component.computed }
	for (const [name, getter] of Object.entries(computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	return ctx
}

/**
 * An axios error with a JSON body.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The body.
 * @return {Error} The error.
 */
function httpError(status, data) {
	return Object.assign(new Error('Request failed'), { response: { status, data } })
}

const CHANNELS = [
	{ channelId: 'teams', label: 'Microsoft Teams', supportsLocation: false, fields: [] },
	{ channelId: 'public-space-report', label: 'Public space report', supportsLocation: true, fields: ['category'] },
]

beforeEach(() => {
	vi.clearAllMocks()
})

describe('IntakeRuleEditorModal', () => {
	/**
	 * An open editor with the channels and one case type loaded.
	 *
	 * @param {object} props The slot props.
	 * @return {object} The context.
	 */
	function openEditor(props = {}) {
		const ctx = context(editor, { show: true, item: null, refresh: vi.fn(), close: vi.fn(), ...props })
		ctx.channels = CHANNELS
		ctx.caseTypes = [{ id: 'case', label: 'Zaken – Zaak', register: 'Zaken', properties: ['title', 'description'] }]
		ctx.draft = { ...ctx.draft, name: 'Teams naar zaak', channelId: 'teams', targetSchema: 'case' }
		return ctx
	}

	it('lists only the channels the intake registry returned', () => {
		const ctx = openEditor()
		expect(ctx.channelOptions).toEqual([
			{ id: 'teams', label: 'Microsoft Teams (teams)' },
			{ id: 'public-space-report', label: 'Public space report (public-space-report)' },
		])
	})

	it('creates a rule through POST /api/intake/routing-rules, then refreshes the list and closes', async () => {
		axios.post.mockResolvedValue({ data: { id: 'new-uuid', rule: {} } })
		const ctx = openEditor()

		expect(await ctx.onSave()).toBe(true)

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules', {
			name: 'Teams naar zaak',
			channelId: 'teams',
			condition: {},
			targetSchema: 'case',
			fieldMapping: {},
			order: 10,
			isEnabled: true,
		})
		expect(axios.put).not.toHaveBeenCalled()
		expect(ctx.refresh).toHaveBeenCalledTimes(1)
		expect(ctx.close).toHaveBeenCalledTimes(1)
	})

	it('updates an existing rule through PUT with its uuid', async () => {
		axios.put.mockResolvedValue({ data: { id: 'r1', rule: {} } })
		const ctx = openEditor({ item: { '@self': { id: 'r1' }, name: 'Oud' } })

		await ctx.onSave()

		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules/r1', expect.objectContaining({ name: 'Teams naar zaak' }))
	})

	it('keeps the dialog open and shows a missing case field on its mapping row', async () => {
		const refusal = 'Case type "case" has no field "straatnaam".'
		axios.post.mockRejectedValue(httpError(400, { error: refusal }))
		const ctx = openEditor()
		ctx.draft = { ...ctx.draft, mappings: [{ target: 'straatnaam', source: 'fields.street' }] }

		expect(await ctx.onSave()).toBe(false)

		expect(ctx.fieldErrors.fieldMapping).toBe(refusal)
		expect(ctx.mappingErrorTarget).toBe('straatnaam')
		expect(ctx.saveError).toBe('')
		expect(ctx.saving).toBe(false)
		expect(ctx.close).not.toHaveBeenCalled()
		expect(ctx.refresh).not.toHaveBeenCalled()
	})

	it('shows a refusal that names no field above the form', async () => {
		axios.post.mockRejectedValue(httpError(403, { message: 'Not allowed' }))
		const ctx = openEditor()

		await ctx.onSave()

		expect(ctx.saveError).toBe('Not allowed')
		expect(ctx.close).not.toHaveBeenCalled()
	})

	it('does not save without a channel', async () => {
		const ctx = openEditor()
		ctx.draft = { ...ctx.draft, channelId: '' }

		expect(await ctx.onSave()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('tests the draft against typed text and reports the match, payload and winner', async () => {
		axios.post.mockResolvedValue({
			data: { matches: true, payload: { title: 'lantaarnpaal kapot' }, winningRule: { name: 'Oud', order: 5 } },
		})
		const ctx = openEditor()
		ctx.sampleText = 'lantaarnpaal kapot'

		await ctx.runTest()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules/test', {
			rule: expect.objectContaining({ channelId: 'teams', targetSchema: 'case' }),
			sample: { text: 'lantaarnpaal kapot' },
		})
		expect(ctx.payloadRows).toEqual([{ field: 'title', value: 'lantaarnpaal kapot' }])
		expect(ctx.winningRuleText).toBe('Today the saved rule "Oud" (order 5) wins for this message.')
		expect(ctx.testing).toBe(false)
	})

	it('says a message would be held when no saved rule wins', async () => {
		axios.post.mockResolvedValue({ data: { matches: false, payload: [], winningRule: null } })
		const ctx = openEditor()

		await ctx.runTest()

		expect(ctx.payloadRows).toEqual([])
		expect(ctx.winningRuleText).toBe('No saved rule matches this message today, so it would be held.')
	})

	it('shows a test refusal instead of a result', async () => {
		axios.post.mockRejectedValue(httpError(400, { error: 'No intake channel adapter answers to "x".' }))
		const ctx = openEditor()

		await ctx.runTest()

		expect(ctx.testResult).toBeNull()
		expect(ctx.testError).toBe('No intake channel adapter answers to "x".')
	})

	it('offers the location field only on a channel with a location', () => {
		const ctx = openEditor()
		expect(ctx.selectedChannelSupportsLocation).toBe(false)
		ctx.draft = { ...ctx.draft, channelId: 'public-space-report' }
		expect(ctx.selectedChannelSupportsLocation).toBe(true)
		expect(ctx.sourceOptions).toContain('fields.category')
	})
})

describe('intake rule row handlers', () => {
	const first = { '@self': { id: 'r1' }, name: 'Eerste', channelId: 'teams', targetSchema: 'case', order: 10, isEnabled: true }
	const second = { '@self': { id: 'r2' }, name: 'Tweede', channelId: 'teams', targetSchema: 'case', order: 20, isEnabled: true }

	it('moves a rule up by writing both orders through saveRule and signals the list', async () => {
		axios.get.mockResolvedValue({ data: { results: [first, second] } })
		axios.put.mockResolvedValue({ data: {} })

		expect(await handlers.moveIntakeRuleUpHandler({ item: second })).toBe(true)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/intake_routing_rule', {
			params: { channelId: 'teams', _limit: 500 },
		})
		expect(axios.put.mock.calls).toEqual([
			['/index.php/apps/integriq/api/intake/routing-rules/r2', { name: 'Tweede', channelId: 'teams', targetSchema: 'case', order: 10, isEnabled: true }],
			['/index.php/apps/integriq/api/intake/routing-rules/r1', { name: 'Eerste', channelId: 'teams', targetSchema: 'case', order: 20, isEnabled: true }],
		])
		expect(library.dispatchObjectsChanged).toHaveBeenCalledWith({ register: 'integriq', schema: 'intake_routing_rule' })
	})

	it('writes nothing when the first rule moves up', async () => {
		axios.get.mockResolvedValue({ data: { results: [first, second] } })

		expect(await handlers.moveIntakeRuleUpHandler({ item: first })).toBe(false)

		expect(axios.put).not.toHaveBeenCalled()
		expect(dialogs.showError).toHaveBeenCalledWith('This rule is already first on its channel.')
	})

	it('disables an enabled rule through saveRule with the rest of the rule unchanged', async () => {
		axios.put.mockResolvedValue({ data: {} })

		expect(await handlers.toggleIntakeRuleHandler({ item: first })).toBe(true)

		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules/r1', {
			name: 'Eerste', channelId: 'teams', targetSchema: 'case', order: 10, isEnabled: false,
		})
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Rule disabled')
	})

	it('shows the refusal and signals nothing when enabling is refused', async () => {
		axios.put.mockRejectedValue(httpError(400, { error: 'Case type "case" has no field "x".' }))

		expect(await handlers.toggleIntakeRuleHandler({ item: { ...second, isEnabled: false } })).toBe(false)

		expect(dialogs.showError).toHaveBeenCalledWith('Case type "case" has no field "x".')
		expect(library.dispatchObjectsChanged).not.toHaveBeenCalled()
	})
})
