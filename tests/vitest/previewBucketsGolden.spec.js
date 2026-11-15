// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the preview step markup of the import dialog and the
 * promote modal: the create, update and collision buckets with and without
 * entries.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ImportPreviewDialog from '@/dialogs/ImportPreviewDialog.vue'
import PromotePreviewModal from '@/modals/PromotePreviewModal.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: { results: [] } }),
		post: vi.fn().mockResolvedValue({ data: {} }),
	},
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: [
				'type',
				'variant',
				'disabled',
				'name',
				'open',
				'labelId',
				'size',
			],
			render() {
				return h('div', { class: name, 'data-type': this.type }, [
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

const FULL = {
	creates: [
		{ type: 'source', slug: 'a' },
		{ type: 'mapping', slug: 'b' },
	],
	updates: [{ type: 'endpoint', slug: 'c' }],
	collisions: [{ type: 'rule', slug: 'd', reason: 'taken' }],
	unresolvedReferences: [],
	credentialRefsNeedingRebind: [],
	credentialsNeedingReentry: [],
}
const EMPTY = {
	creates: [],
	updates: [],
	collisions: [],
	unresolvedReferences: [],
	credentialRefsNeedingRebind: [],
	credentialsNeedingReentry: [],
}

/**
 * The rendered markup without scoped-style ids, HTML comments and the
 * trailing spaces before a closing tag, which a block element does not render.
 *
 * @param {object} wrapper the mounted dialog
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n?\s*<!--[\s\S]*?-->/g, '')
		.replace(/ +<\//g, '</')
}

describe.each([
	['ImportPreviewDialog', ImportPreviewDialog, {}],
	[
		'PromotePreviewModal',
		PromotePreviewModal,
		{
			selectedConfig: { id: 'c', label: 'Config' },
			selectedEnvironment: { id: 'e', label: 'Env' },
		},
	],
])('%s preview step', (_name, component, extra) => {
	it.each([
		['with entries', FULL],
		['empty', EMPTY],
	])('renders %s as recorded', async (_label, preview) => {
		const wrapper = mount(component, {
			props: { open: false },
			global: { mocks: { t: translate } },
		})
		await wrapper.setProps({ open: true })
		await flushPromises()
		Object.assign(wrapper.vm, extra, { step: 'preview', preview })
		await flushPromises()
		expect(markup(wrapper)).toMatchSnapshot()
	})
})
