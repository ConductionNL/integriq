// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of ImportPreviewDialog: reading the chosen file, the
 * preview request, and the confirmed import, with every failure text.
 *
 * @spec openspec/specs/connector-catalog-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ImportPreviewDialog from '@/dialogs/ImportPreviewDialog.vue'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			render() {
				return h('div', { class: name }, [
					this.$slots.default?.(),
					this.$slots.actions?.(),
				])
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) =>
				typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined,
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})

globalThis.t = translate

const FIELDS = [
	'step',
	'document',
	'preview',
	'unresolvedAcknowledged',
	'importRunning',
	'errorMessage',
]

/**
 * The dialog state the golden pins.
 *
 * @param {object} vm the dialog
 * @return {object} the state
 */
function state(vm) {
	return JSON.parse(
		JSON.stringify(Object.fromEntries(FIELDS.map((f) => [f, vm[f] ?? null]))),
	)
}

/**
 * An input change event carrying one file with the given text.
 *
 * @param {string|null} text the file contents, or null for no file
 * @return {object} the event
 */
function chosen(text) {
	return {
		target: {
			files: text === null ? [] : [{ text: () => Promise.resolve(text) }],
		},
	}
}

/**
 * An open dialog.
 *
 * @return {Promise<object>} the wrapper
 */
async function openDialog() {
	const wrapper = mount(ImportPreviewDialog, {
		props: { open: false },
		global: { mocks: { t: translate } },
	})
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	post.mockReset()
})

describe('ImportPreviewDialog', () => {
	it.each([
		['no file', null, null],
		['invalid JSON', '{bad', null],
		[
			'a document',
			'{"a":1}',
			() => Promise.resolve({ data: { creates: [{ type: 's', slug: 'x' }] } }),
		],
		['a null document', 'null', () => Promise.resolve({ data: null })],
		[
			'a refused preview',
			'{"a":1}',
			() => Promise.reject({ response: { data: { error: 'srv' } } }),
		],
		['a failed preview', '{"a":1}', () => Promise.reject(new Error('net'))],
		['a bare failure', '{"a":1}', () => Promise.reject({})],
	])('previews %s as recorded', async (_label, text, answer) => {
		const wrapper = await openDialog()
		wrapper.vm.errorMessage = 'old'
		wrapper.vm.unresolvedAcknowledged = true
		if (answer) post.mockImplementation(answer)
		await wrapper.vm.onFileChosen(chosen(text))
		await flushPromises()
		expect({
			calls: post.mock.calls,
			state: state(wrapper.vm),
		}).toMatchSnapshot()
	})

	it.each([
		[
			'an import',
			() => Promise.resolve({ data: { creates: [{ type: 's', slug: 'x' }] } }),
		],
		[
			'a refused import',
			() => Promise.reject({ response: { data: { error: 'srv' } } }),
		],
		['a failed import', () => Promise.reject(new Error('net'))],
		['a bare failure', () => Promise.reject({})],
	])('confirms %s as recorded', async (_label, answer) => {
		const wrapper = await openDialog()
		post.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.onFileChosen(chosen('{"a":1}'))
		await flushPromises()
		post.mockReset()
		post.mockImplementation(answer)
		wrapper.vm.errorMessage = 'old'
		const running = wrapper.vm.confirmImport()
		const during = wrapper.vm.importRunning
		await running
		expect({
			during,
			calls: post.mock.calls,
			state: state(wrapper.vm),
		}).toMatchSnapshot()
	})

	it('does not import without a document or with unacknowledged references', async () => {
		const wrapper = await openDialog()
		await wrapper.vm.confirmImport()
		post.mockResolvedValueOnce({
			data: { unresolvedReferences: [{ ref: 'x' }] },
		})
		await wrapper.vm.onFileChosen(chosen('{"a":1}'))
		await flushPromises()
		post.mockReset()
		await wrapper.vm.confirmImport()
		expect(post).not.toHaveBeenCalled()
		wrapper.vm.unresolvedAcknowledged = true
		post.mockResolvedValue({ data: {} })
		await wrapper.vm.confirmImport()
		expect(post).toHaveBeenCalledTimes(1)
	})
})
