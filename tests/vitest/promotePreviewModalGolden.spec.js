// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of PromotePreviewModal's state and requests: the initial
 * state, the two option lists it loads on open, the preview and the
 * confirmation calls, and the error text each failure leaves on screen.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PromotePreviewModal from '@/modals/PromotePreviewModal.vue'

const { get, post, showSuccess } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'close', 'update:modelValue'],
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	return {
		NcModal: stub('NcModal', ['labelId', 'size']),
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcSelect: stub('NcSelect', [
			'modelValue',
			'options',
			'loading',
			'inputLabel',
			'placeholder',
			'label',
		]),
		NcLoadingIcon: stub('NcLoadingIcon', ['size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue']),
	}
})

globalThis.t = translate

const CONFIG_URL = '/index.php/apps/openregister/api/configurations'
const ENV_URL = '/index.php/apps/integriq/api/environments'

/**
 * Answer the two option lists the modal loads on open.
 *
 * @param {object} answers url => resolved value or Error
 * @return {void}
 */
function answerGets(answers) {
	get.mockImplementation((url) => {
		const answer = answers[url]
		return answer instanceof Error
			? Promise.reject(answer)
			: Promise.resolve(answer)
	})
}

/**
 * An axios-like failure.
 *
 * @param {object} shape fields of the error
 * @return {Error}
 */
function failure(shape) {
	return Object.assign(new Error(shape.message ?? ''), shape)
}

/**
 * Mount the modal closed, with the global t() the app registers.
 *
 * @return {object} the wrapper
 */
function mountClosed() {
	return mount(PromotePreviewModal, {
		props: { open: false },
		global: { mocks: { t: translate } },
	})
}

/**
 * Mount the modal closed, then open it so the watcher runs.
 *
 * @return {Promise<object>} the wrapper
 */
async function openModal() {
	const wrapper = mountClosed()
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

const CONFIG_ROWS = {
	data: {
		results: [
			{ uuid: 'u-1', id: 1, title: 'Title one', name: 'Name one' },
			{ id: 2, name: 'Name two' },
			{ uuid: 'u-3', id: 3 },
			{ id: 4 },
		],
	},
}
const ENV_ROWS = {
	data: { results: [{ slug: 'acc', name: 'Acceptance' }, { slug: 'prod' }] },
}

beforeEach(() => {
	get.mockReset()
	post.mockReset()
	showSuccess.mockReset()
})

describe('initial state', () => {
	it('starts on the select step with nothing chosen', () => {
		const wrapper = mountClosed()
		expect(wrapper.vm.$data).toEqual({
			step: 'select',
			configOptions: [],
			selectedConfig: null,
			loadingConfigs: false,
			environmentOptions: [],
			selectedEnvironment: null,
			loadingEnvironments: false,
			previewing: false,
			confirming: false,
			preview: null,
			credentialBindings: {},
			overrideFailingTests: false,
			errorMessage: '',
		})
	})
})

describe('loading the option lists', () => {
	it('maps configuration groups and environments with their fallbacks', async () => {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()

		expect(get.mock.calls.map((call) => call[0])).toEqual([CONFIG_URL, ENV_URL])
		expect(wrapper.vm.configOptions).toEqual([
			{ id: 'u-1', label: 'Title one' },
			{ id: 2, label: 'Name two' },
			{ id: 'u-3', label: 'u-3' },
			{ id: 4, label: '4' },
		])
		expect(wrapper.vm.environmentOptions).toEqual([
			{ id: 'acc', label: 'Acceptance' },
			{ id: 'prod', label: 'prod' },
		])
		expect(wrapper.vm.loadingConfigs).toBe(false)
		expect(wrapper.vm.loadingEnvironments).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('treats a missing results list as empty', async () => {
		answerGets({ [CONFIG_URL]: { data: {} }, [ENV_URL]: { data: null } })
		const wrapper = await openModal()
		expect(wrapper.vm.configOptions).toEqual([])
		expect(wrapper.vm.environmentOptions).toEqual([])
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('shows the loading flags while the requests are pending', async () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountClosed()
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.loadingConfigs).toBe(true)
		expect(wrapper.vm.loadingEnvironments).toBe(true)
	})

	it.each([
		[failure({ response: { data: { error: 'srv' } }, message: 'msg' }), ': srv'],
		[failure({ message: 'msg' }), ': msg'],
		[failure({}), ''],
	])('a failed configuration list names its detail (%#)', async (err, suffix) => {
		answerGets({ [CONFIG_URL]: err, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()
		expect(wrapper.vm.errorMessage).toBe(
			'Could not load configuration groups' + suffix,
		)
		expect(wrapper.vm.configOptions).toEqual([])
		expect(wrapper.vm.loadingConfigs).toBe(false)
	})

	it.each([
		[failure({ response: { data: { error: 'srv' } }, message: 'msg' }), ': srv'],
		[failure({ message: 'msg' }), ': msg'],
		[failure({}), ''],
	])('a failed environment list names its detail (%#)', async (err, suffix) => {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: err })
		const wrapper = await openModal()
		expect(wrapper.vm.errorMessage).toBe('Could not load environments' + suffix)
		expect(wrapper.vm.environmentOptions).toEqual([])
		expect(wrapper.vm.loadingEnvironments).toBe(false)
	})

	it('reopening resets a previous run', async () => {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()
		Object.assign(wrapper.vm, {
			step: 'preview',
			selectedConfig: { id: 'x' },
			selectedEnvironment: { id: 'y' },
			preview: {},
			credentialBindings: { a: 'b' },
			errorMessage: 'old',
		})
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(wrapper.vm.step).toBe('select')
		expect(wrapper.vm.selectedConfig).toBeNull()
		expect(wrapper.vm.selectedEnvironment).toBeNull()
		expect(wrapper.vm.preview).toBeNull()
		expect(wrapper.vm.credentialBindings).toEqual({})
		expect(wrapper.vm.errorMessage).toBe('')
	})
})

describe('the preview', () => {
	/**
	 * An opened modal with both selections made.
	 *
	 * @return {Promise<object>} the wrapper
	 */
	async function selected() {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()
		wrapper.vm.onSelectConfig({ id: 'u-1', label: 'Title one' })
		wrapper.vm.onSelectEnvironment({ id: 'acc', label: 'Acceptance' })
		return wrapper
	}

	it('does nothing without both selections', async () => {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()
		wrapper.vm.onSelectConfig({ id: 'u-1' })
		await wrapper.vm.runPreview()
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.previewing).toBe(false)
	})

	it('posts the selection with empty bindings and fills in missing buckets', async () => {
		const wrapper = await selected()
		wrapper.vm.errorMessage = 'old'
		post.mockResolvedValue({
			data: { creates: [{ type: 'source', slug: 's' }], collisions: null },
		})
		await wrapper.vm.runPreview()
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/promotions/preview',
			{
				configurationId: 'u-1',
				targetEnvironmentSlug: 'acc',
				credentialBindings: [],
			},
		)
		expect(wrapper.vm.preview).toEqual({
			creates: [{ type: 'source', slug: 's' }],
			updates: [],
			collisions: [],
			credentialRefsNeedingRebind: [],
			failingTestCases: [],
		})
		expect(wrapper.vm.step).toBe('preview')
		expect(wrapper.vm.previewing).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('keeps every bucket the target returned', async () => {
		const wrapper = await selected()
		const data = {
			creates: [1],
			updates: [2],
			collisions: [3],
			credentialRefsNeedingRebind: [4],
			failingTestCases: [5],
		}
		post.mockResolvedValue({ data })
		await wrapper.vm.runPreview()
		expect(wrapper.vm.preview).toEqual(data)
	})

	it('marks the preview as running until it answers', async () => {
		const wrapper = await selected()
		post.mockReturnValue(new Promise(() => {}))
		wrapper.vm.runPreview()
		expect(wrapper.vm.previewing).toBe(true)
	})

	it.each([
		[failure({ response: { data: { error: 'srv' } }, message: 'msg' }), ': srv'],
		[failure({ message: 'msg' }), ': msg'],
		[failure({}), ''],
	])('a failed preview stays on the select step (%#)', async (err, suffix) => {
		const wrapper = await selected()
		post.mockRejectedValue(err)
		await wrapper.vm.runPreview()
		expect(wrapper.vm.errorMessage).toBe('Preview failed' + suffix)
		expect(wrapper.vm.step).toBe('select')
		expect(wrapper.vm.preview).toBeNull()
		expect(wrapper.vm.previewing).toBe(false)
	})
})

describe('the confirmation', () => {
	/**
	 * An opened modal on the preview step with two flagged sources.
	 *
	 * @return {Promise<object>} the wrapper
	 */
	async function previewed() {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()
		wrapper.vm.onSelectConfig({ id: 'u-1', label: 'Title one' })
		wrapper.vm.onSelectEnvironment({ id: 'acc', label: 'Acceptance' })
		post.mockResolvedValueOnce({
			data: {
				credentialRefsNeedingRebind: [
					{ slug: 'a', field: 'credentialName' },
					{ slug: 'b', field: 'credentialId' },
					{ slug: 'c', field: 'credentialName' },
				],
			},
		})
		await wrapper.vm.runPreview()
		post.mockReset()
		wrapper.vm.credentialBindings = {
			'a|credentialName': '  target-a ',
			'b|credentialId': '   ',
		}
		return wrapper
	}

	it('posts the confirmed promotion with the filled-in bindings only', async () => {
		const wrapper = await previewed()
		wrapper.vm.errorMessage = 'old'
		post.mockResolvedValue({ data: {} })
		await wrapper.vm.confirmPromotion()
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/promotions',
			{
				configurationId: 'u-1',
				targetEnvironmentSlug: 'acc',
				credentialBindings: [
					{
						sourceSlug: 'a',
						field: 'credentialName',
						credentialName: 'target-a',
					},
				],
				confirmed: true,
			},
		)
		expect(showSuccess).toHaveBeenCalledWith(
			'Configuration promoted to Acceptance.',
		)
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.confirming).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('marks the confirmation as running until it answers', async () => {
		const wrapper = await previewed()
		post.mockReturnValue(new Promise(() => {}))
		wrapper.vm.confirmPromotion()
		expect(wrapper.vm.confirming).toBe(true)
	})

	it.each([
		[failure({ response: { data: { error: 'srv' } }, message: 'msg' }), ': srv'],
		[failure({ message: 'msg' }), ': msg'],
		[failure({}), ''],
	])('a failed promotion stays open (%#)', async (err, suffix) => {
		const wrapper = await previewed()
		post.mockRejectedValue(err)
		await wrapper.vm.confirmPromotion()
		expect(wrapper.vm.errorMessage).toBe('Promotion failed' + suffix)
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.confirming).toBe(false)
	})

	it('a second preview still sends no bindings', async () => {
		const wrapper = await previewed()
		post.mockResolvedValue({ data: {} })
		await wrapper.vm.runPreview()
		expect(post.mock.calls[0][1].credentialBindings).toEqual([])
	})

	it('back discards the preview', async () => {
		const wrapper = await previewed()
		wrapper.vm.backToSelect()
		expect(wrapper.vm.step).toBe('select')
		expect(wrapper.vm.preview).toBeNull()
		expect(wrapper.vm.buildCredentialBindings()).toEqual([])
	})
})

describe('failing test cases (REQ-IRT-005)', () => {
	it('hold the confirm back until the override is ticked, and the override is sent', async () => {
		answerGets({ [CONFIG_URL]: CONFIG_ROWS, [ENV_URL]: ENV_ROWS })
		const wrapper = await openModal()
		wrapper.vm.onSelectConfig({ id: 'u-1', label: 'Title one' })
		wrapper.vm.onSelectEnvironment({ id: 'acc', label: 'Acceptance' })
		post.mockResolvedValue({ data: { creates: [], failingTestCases: [{ caseId: 'c-1', caseName: 'Vacature', subjectType: 'mapping', subjectId: 'm' }] } })
		await wrapper.vm.runPreview()

		expect(wrapper.vm.testsBlockConfirm).toBe(true)
		expect(wrapper.vm.confirmedRequest()).not.toHaveProperty('overrideFailingTests')
		wrapper.vm.overrideFailingTests = true
		expect(wrapper.vm.testsBlockConfirm).toBe(false)
		expect(wrapper.vm.confirmedRequest()).toMatchObject({ confirmed: true, overrideFailingTests: true })
	})
})
