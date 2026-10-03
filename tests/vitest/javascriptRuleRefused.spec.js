// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A JavaScript rule is refused, not ignored
 * (gateway-endpoint-transform-and-plugins REQ-GTP-003).
 *
 * The editor offered JavaScript with a code box, and the runtime returned the
 * data unchanged, so a rule that did nothing looked like it worked. Integriq
 * runs no scripts: the type is no longer offered, and a rule that still
 * carries it shows why it fails instead of a code box.
 *
 * @spec openspec/specs/rule-pipeline/spec.md#requirement-a-javascript-rule-is-refused-req-gtp-003
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RuleActionConfig from '@/views/Rule/RuleActionConfig.vue'
import { ACTION_TYPES } from '@/views/Rule/ruleDraft.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve; the components are stood in by named stubs.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	// The action forms RuleActionConfig imports pull in these too.
	const names = [
		'NcButton',
		'NcCheckboxRadioSwitch',
		'NcInputField',
		'NcNoteCard',
		'NcPasswordField',
		'NcSelect',
		'NcTextArea',
		'NcTextField',
	]
	return Object.fromEntries(
		names.map((name) => [name, stub(name, ['type', 'modelValue', 'options'])]),
	)
})

const global = { mocks: { t: (_app, text) => text } }

describe('the JavaScript rule', () => {
	it('is not offered in the rule type list', () => {
		expect(ACTION_TYPES.map((entry) => entry.id)).not.toContain('javascript')
	})

	it('shows an old JavaScript rule as refused, with no code box', () => {
		const wrapper = mount(RuleActionConfig, {
			props: {
				configuration: { type: 'javascript', javascript: 'return data' },
				type: 'javascript',
			},
			global,
		})

		const note = wrapper.find('[data-testid="rule-action-javascript-refused"]')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain('Integriq runs no scripts')
		expect(wrapper.find('textarea').exists()).toBe(false)
	})
})
