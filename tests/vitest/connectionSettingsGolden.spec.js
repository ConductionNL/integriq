// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the two intake connection editors (DSO signature trust
 * and Open Formulieren): the markup, what load() reads, what save() sends,
 * the toasts and the error state of every failure.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DsoPkiSettings from '@/views/admin/DsoPkiSettings.vue'
import OpenFormulierenConnectionSettings from '@/views/admin/OpenFormulierenConnectionSettings.vue'

const { get, put, toasts } = vi.hoisted(() => ({
	get: vi.fn(),
	put: vi.fn(),
	toasts: [],
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
	showWarning: (text) => toasts.push(['warning', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: [
				'modelValue',
				'inputLabel',
				'label',
				'options',
				'placeholder',
				'type',
				'disabled',
				'variant',
				'loading',
			],
			emits: ['update:modelValue', 'search'],
			setup(props, { attrs, slots }) {
				return () =>
					h(
						tag,
						{
							class: name,
							'data-testid': attrs['data-testid'],
							'data-label': props.inputLabel ?? props.label,
							'data-placeholder': props.placeholder,
							'data-disabled': props.disabled,
							onClick: attrs.onClick,
						},
						slots.default?.(),
					)
			},
		})
	return {
		NcButton: stub('NcButton', 'button'),
		NcNoteCard: stub('NcNoteCard'),
		NcPasswordField: stub('NcPasswordField'),
		NcSelect: stub('NcSelect'),
		NcSelectUsers: stub('NcSelectUsers'),
		NcTextField: stub('NcTextField'),
	}
})

const EDITORS = [
	{
		name: 'DsoPkiSettings',
		component: DsoPkiSettings,
		url: '/index.php/apps/integriq/api/admin/dso-pki-config',
		full: {
			mode: 'pkioverheid',
			hmacSecretConfigured: true,
			signingCertificate: 'CERT',
			intermediateChain: 'CHAIN',
			rootCa: 'ROOT',
			handlerGroup: { id: 'dso-handlers', empty: true },
			account: { state: 'ok', displayName: 'Intake Bot' },
			userId: 'intake',
		},
		fields: [
			'mode',
			'hmacSecret',
			'hmacSecretConfigured',
			'signingCertificate',
			'intermediateChain',
			'rootCa',
			'modeOptions',
		],
		edit: { mode: 'hmac', hmacSecret: 's3cret', rootCa: 'R2' },
		secret: 'hmacSecret',
	},
	{
		name: 'OpenFormulierenConnectionSettings',
		component: OpenFormulierenConnectionSettings,
		url: '/index.php/apps/integriq/api/admin/open-formulieren-connection',
		full: {
			scheme: 'github',
			secretConfigured: true,
			header: 'X-Sig',
			toleranceSeconds: 60,
			handlerGroup: { id: 'of-handlers', empty: true },
			account: { state: 'ok', displayName: 'Intake Bot' },
			userId: 'intake',
		},
		fields: [
			'scheme',
			'secret',
			'secretConfigured',
			'header',
			'toleranceSeconds',
			'schemeOptions',
		],
		edit: { scheme: 'stripe', secret: 's3cret', toleranceSeconds: 'abc' },
		secret: 'secret',
	},
]

const STATE = [
	'loading',
	'saving',
	'error',
	'accountError',
	'account',
	'accountInfo',
	'handlerGroup',
]

/**
 * The editor state the golden pins.
 *
 * @param {object} editor the editor case
 * @param {object} vm the component instance
 * @return {object} the fields
 */
function state(editor, vm) {
	return Object.fromEntries(
		[...STATE, ...editor.fields, 'accountState'].map((k) => [
			k,
			JSON.parse(JSON.stringify(vm[k] ?? null)),
		]),
	)
}

/**
 * Mount an editor after scripting its first load.
 *
 * @param {object} editor the editor case
 * @param {Function} answer what the first GET returns
 * @return {Promise<object>} the wrapper
 */
async function mountWith(editor, answer) {
	get.mockImplementationOnce(answer)
	const wrapper = mount(editor.component, {
		global: { mocks: { t: translate } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The rendered markup without the scoped-style ids, which follow the file.
 *
 * @param {object} wrapper the mounted editor
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/ data-v-[0-9a-f]+=""/g, '')
}

const ok = (data) => () => Promise.resolve({ data })

beforeEach(() => {
	get.mockReset()
	put.mockReset()
	toasts.length = 0
})

describe.each(EDITORS)('$name', (editor) => {
	it('starts loading with the defaults', () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(editor.component, {
			global: { mocks: { t: translate } },
		})
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('loads a full configuration', async () => {
		const wrapper = await mountWith(editor, ok(editor.full))
		expect(get).toHaveBeenCalledWith(editor.url)
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('loads an empty configuration', async () => {
		const wrapper = await mountWith(editor, ok({}))
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('reads unexpected values strictly', async () => {
		const wrapper = await mountWith(
			editor,
			ok({
				mode: 'other',
				secretConfigured: 'yes',
				hmacSecretConfigured: 'yes',
			}),
		)
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
	})

	it('an account without display name shows its id', async () => {
		const wrapper = await mountWith(
			editor,
			ok({ userId: 'u1', account: { state: 'disabled', displayName: '' } }),
		)
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
	})

	it('a failed load says so', async () => {
		const wrapper = await mountWith(editor, () => Promise.reject(new Error('x')))
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('a broken answer counts as a failed load', async () => {
		const wrapper = await mountWith(editor, ok(null))
		expect(state(editor, wrapper.vm).error).not.toBe('')
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([
		['with an account', true],
		['without an account', false],
	])('saves %s, reloads and warns', async (_label, withAccount) => {
		const wrapper = await mountWith(editor, ok(editor.full))
		Object.assign(wrapper.vm, editor.edit, { error: 'old', accountError: 'old' })
		if (!withAccount) {
			wrapper.vm.account = null
		}
		put.mockResolvedValue({ data: { warnings: ['w1', 'w2'] } })
		get.mockImplementationOnce(ok({ ...editor.full, userId: 'after' }))
		const saving = wrapper.vm.save()
		expect(wrapper.vm.saving).toBe(true)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.accountError).toBe('')
		await saving
		await flushPromises()
		expect(put.mock.calls).toMatchSnapshot()
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm[editor.secret]).toBe('')
		expect(toasts).toMatchSnapshot()
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
	})

	it('a save without warnings only says saved', async () => {
		const wrapper = await mountWith(editor, ok(editor.full))
		put.mockResolvedValue({ data: {} })
		get.mockImplementationOnce(ok(editor.full))
		await wrapper.vm.save()
		await flushPromises()
		expect(toasts).toMatchSnapshot()
	})

	it.each([
		[
			'field and list errors',
			{
				response: {
					data: {
						fieldErrors: { userId: 'no such user' },
						errors: ['a', 'b'],
					},
				},
			},
		],
		['errors that are not a list', { response: { data: { errors: 'x' } } }],
		['a bare response', { response: {} }],
		['no response', new Error('net')],
	])('a failed save with %s', async (_label, err) => {
		const wrapper = await mountWith(editor, ok(editor.full))
		Object.assign(wrapper.vm, editor.edit)
		put.mockRejectedValue(err)
		await wrapper.vm.save()
		expect(get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm[editor.secret]).toBe(editor.edit[editor.secret])
		expect(toasts).toMatchSnapshot()
		expect(state(editor, wrapper.vm)).toMatchSnapshot()
	})
})
