// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule editor's lockout dialog (access-oauth-and-token-validation,
 * REQ-TOKV-005): it names each consumer with the scopes it lacks, says where
 * the list comes from, and its two buttons cancel or confirm the save.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ScopeLockoutDialog from '../../src/modals/Rule/ScopeLockoutDialog.vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcDialog: defineComponent({
			name: 'NcDialog',
			props: ['name'],
			emits: ['closing'],
			render() {
				return h('div', { class: 'NcDialog', 'data-name': this.name }, [this.$slots.default?.(), this.$slots.actions?.()])
			},
		}),
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant'],
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
	}
})

/**
 * Mount the dialog.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountDialog(props) {
	return mount(ScopeLockoutDialog, { props, global: { mocks: { t: translate } } })
}

const consumers = [
	{ id: 'c-1', name: 'Zaaksysteem', missing: ['zaken:write'] },
	{ id: 'c-2', name: 'Portaal', missing: ['zaken:read', 'zaken:write'] },
]

describe('ScopeLockoutDialog', () => {
	it('lists each consumer with the scopes it lacks', () => {
		const rows = mountDialog({ consumers, fromCallLog: true }).findAll('[data-testid="rule-scope-lockout-consumer"]')
		expect(rows.map((row) => row.text())).toEqual([
			'Zaaksysteem lacks zaken:write',
			'Portaal lacks zaken:read, zaken:write',
		])
	})

	it('says whether the list comes from the call log', () => {
		expect(mountDialog({ consumers, fromCallLog: true }).text()).toContain('call an endpoint that uses this rule')
		expect(mountDialog({ consumers, fromCallLog: false }).text()).toContain('No calls to the endpoints of this rule are recorded')
	})

	it('emits cancel and confirm from its buttons and cancel on close', async () => {
		const wrapper = mountDialog({ consumers })
		await wrapper.find('[data-testid="rule-scope-lockout-cancel"]').trigger('click')
		await wrapper.find('[data-testid="rule-scope-lockout-confirm"]').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('cancel')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})
})
