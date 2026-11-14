/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for AddEndpointRuleModal (src/modals/v2/AddEndpointRuleModal.vue):
 * which rules it offers, how it merges the picked rules into the endpoint, and
 * what it shows when a request fails. The component logic runs over a plain
 * context; only HTTP and the toast helpers are mocked.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const patch = vi.fn()
const showError = vi.fn()
const showSuccess = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get, patch } }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcModal: {},
	NcNoteCard: {},
	NcSelect: {},
}))
vi.mock('vue-material-design-icons/Cancel.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ default: {} }))

const modal = (await import('@/modals/v2/AddEndpointRuleModal.vue')).default

/**
 * A component context over the given props, with emits recorded.
 *
 * @param {object} props The modal props.
 * @return {object} The context.
 */
function vm(props) {
	const ctx = { open: false, endpoint: null, emitted: [], ...props }
	ctx.$emit = (...args) => ctx.emitted.push(args)
	Object.assign(ctx, modal.data.call(ctx))
	for (const [name, getter] of Object.entries(modal.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(modal.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	globalThis.t = (app, text) => text
	get.mockReset()
	patch.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	delete globalThis.t
	vi.restoreAllMocks()
})

describe('endpoint identity', () => {
	it('names and identifies the endpoint by name or title, id or uuid', () => {
		expect(vm({ endpoint: { id: 3, name: 'Zaken', title: 'x' } })).toMatchObject({ endpointName: 'Zaken', endpointId: 3 })
		expect(vm({ endpoint: { uuid: 'e-1', title: 'Besluiten' } })).toMatchObject({ endpointName: 'Besluiten', endpointId: 'e-1' })
		expect(vm({ endpoint: null })).toMatchObject({ endpointName: '', endpointId: undefined })
	})

	it('reads the endpoint\'s current rules as string ids, from objects or bare ids', () => {
		const form = vm({ endpoint: { id: 1, rules: [{ id: 7 }, { uuid: 'r-8' }, 9, null, {}, 'r-10'] } })
		expect(form.existingRuleIds).toEqual(['7', 'r-8', '9', 'r-10'])
		expect(vm({ endpoint: { id: 1, rules: 'x' } }).existingRuleIds).toEqual([])
	})
})

describe('opening', () => {
	it('resets the form and loads the rules each time it opens', () => {
		const form = vm({})
		form.resetState = vi.fn()
		form.fetchRules = vi.fn()

		modal.watch.open.call(form, false)
		expect(form.fetchRules).not.toHaveBeenCalled()

		modal.watch.open.call(form, true)
		expect(form.resetState).toHaveBeenCalledOnce()
		expect(form.fetchRules).toHaveBeenCalledOnce()
	})

	it('clears a previous selection, success and error', () => {
		const form = vm({})
		Object.assign(form, { selectedRules: [{ id: '1' }], saving: true, success: true, error: 'old' })
		form.resetState()
		expect(form).toMatchObject({ selectedRules: [], saving: false, success: false, error: '' })
	})

	it('closes on request', () => {
		const form = vm({})
		form.onClose()
		expect(form.emitted).toEqual([['close']])
	})
})

describe('fetchRules', () => {
	it('offers every rule the endpoint does not have yet, labelled by name or title', async () => {
		get.mockResolvedValueOnce({
			data: {
				results: [
					{ id: 7, name: 'Already there' },
					{ uuid: 'r-2', title: 'Second' },
					{ id: 3 },
				],
			},
		})
		const form = vm({ endpoint: { id: 1, rules: [7] } })

		await form.fetchRules()

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/integriq/rule',
			{ params: { _limit: 500 } },
		)
		expect(form.availableRules).toEqual([
			{ id: 'r-2', label: 'Second' },
			{ id: '3', label: 3 },
		])
		expect(form.loadingRules).toBe(false)
	})

	it('accepts a bare list and reads anything else as no rules', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 1, name: 'One' }] })
		get.mockResolvedValueOnce({ data: { results: 'x' } })
		const form = vm({ endpoint: { id: 9 } })

		await form.fetchRules()
		expect(form.availableRules).toEqual([{ id: '1', label: 'One' }])
		await form.fetchRules()
		expect(form.availableRules).toEqual([])
	})

	it('says it could not load the rules when the request fails', async () => {
		get.mockRejectedValueOnce(new Error('500'))
		const form = vm({ endpoint: { id: 9 } })

		await form.fetchRules()

		expect(form.error).toBe('Failed to load available rules.')
		expect(form.loadingRules).toBe(false)
	})
})

describe('onSave', () => {
	it('patches the endpoint with its rules plus the picked ones, without duplicates', async () => {
		patch.mockResolvedValueOnce({})
		const form = vm({ endpoint: { id: 'e-1', rules: [{ id: 7 }, 8] } })
		form.selectedRules = [{ id: 8 }, { id: 'r-9' }]

		await form.onSave()

		expect(patch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/integriq/endpoint/e-1',
			{ rules: ['7', '8', 'r-9'] },
		)
		expect(form).toMatchObject({ success: true, saving: false, error: '' })
		expect(showSuccess).toHaveBeenCalledWith('Rule(s) added to endpoint.')
	})

	it('does nothing without a picked rule or an endpoint id', async () => {
		const empty = vm({ endpoint: { id: 1 } })
		await empty.onSave()
		const anonymous = vm({ endpoint: {} })
		anonymous.selectedRules = [{ id: 1 }]
		await anonymous.onSave()

		expect(patch).not.toHaveBeenCalled()
		expect(empty.canSave).toBe(false)
		expect(anonymous.canSave).toBe(true)
	})

	it('shows the server message, the error message, or just the failure', async () => {
		const failWith = async (err) => {
			patch.mockRejectedValueOnce(err)
			const form = vm({ endpoint: { id: 1 } })
			form.selectedRules = [{ id: 2 }]
			await form.onSave()
			expect(form.saving).toBe(false)
			expect(form.success).toBe(false)
			return form.error
		}

		expect(await failWith({ response: { data: { message: 'Not allowed' } } })).toBe('Failed to add rule to endpoint: Not allowed')
		expect(await failWith(new Error('Network down'))).toBe('Failed to add rule to endpoint: Network down')
		expect(await failWith({})).toBe('Failed to add rule to endpoint')
		expect(showError).toHaveBeenLastCalledWith('Failed to add rule to endpoint')
	})
})
