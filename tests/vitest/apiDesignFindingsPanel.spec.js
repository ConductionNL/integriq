// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The API design findings panel on an API product and its waive dialog
 * (gateway-api-design-rules-check, tasks 3 and 4), against an axios fake.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApiDesignFindingsPanel from '@/components/ApiDesignFindingsPanel.vue'
import WaiveDesignFindingDialog from '@/dialogs/WaiveDesignFindingDialog.vue'
import { findingId, groupBySeverity, isBlocking, replaceFinding } from '@/views/ApiProducts/apiDesignFindings.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const box = (name) => defineComponent({
		name,
		props: ['name', 'open', 'type', 'size'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', { class: 'NcButton', disabled: this.disabled || undefined, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('input', { class: 'NcTextField', value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
			},
		}),
		NcDialog: box('NcDialog'),
		NcNoteCard: box('NcNoteCard'),
		NcLoadingIcon: box('NcLoadingIcon'),
	}
})

const FINDINGS_URL = '/index.php/apps/openregister/api/objects/integriq/api_design_finding'
const CHECK_URL = '/index.php/apps/integriq/api/products/p1/design-check'

const ERROR = { id: 'f1', product: 'p1', ruleId: '/core/naming-resources', severity: 'error', message: 'Use plural nouns', path: '$.paths./zaak', url: 'https://example.org/rule', status: 'open' }
const WARNING = { id: 'f2', product: 'p1', ruleId: '/core/doc-language', severity: 'warning', message: 'Describe in Dutch', status: 'open' }
const WAIVED = { id: 'f3', product: 'p1', ruleId: '/core/version-header', severity: 'error', message: 'Send API-Version', status: 'waived', waivedBy: 'admin', waiveReason: 'Gateway adds it' }

/**
 * Mount the panel on product p1 after the findings load.
 *
 * @return {Promise<object>} The wrapper
 */
async function panel() {
	const wrapper = mount(ApiDesignFindingsPanel, { props: { productId: 'p1' } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	http.get.mockResolvedValue({ data: { results: [WARNING, ERROR, WAIVED, { id: 'other', product: 'p2', severity: 'error' }] } })
})

describe('ApiDesignFindingsPanel', () => {
	it('loads the product\'s stored findings and lists them errors first, with a link to each rule', async () => {
		const wrapper = await panel()

		expect(http.get).toHaveBeenCalledWith(FINDINGS_URL, { params: { product: 'p1', _limit: 500 } })
		const groups = wrapper.findAll('.apiDesignFindings__group h4').map((h) => h.text())
		expect(groups).toEqual(['Errors (2)', 'Warnings (1)'])
		expect(wrapper.find('[data-testid="api-design-group-error"] a').attributes('href')).toBe('https://example.org/rule')
		expect(wrapper.text()).not.toContain('other')
	})

	it('offers a waiver on an open error only, and shows who waived the others and why', async () => {
		const wrapper = await panel()

		expect(wrapper.find('[data-testid="api-design-waive-f1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="api-design-waive-f2"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="api-design-waive-f3"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Waived by admin: Gateway adds it')
	})

	it('says a product was never checked when it has no findings', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await panel()

		expect(wrapper.find('[data-testid="api-design-empty"]').text()).toBe('This product has not been checked yet.')
	})

	it('runs a check and shows what it found and what is left to check by hand', async () => {
		http.post.mockResolvedValue({ data: { findings: [ERROR], manual: [{ id: '/core/publish-openapi', title: 'Publish OAS', url: 'https://example.org/oas' }] } })
		const wrapper = await panel()

		await wrapper.find('[data-testid="api-design-check"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(CHECK_URL)
		expect(wrapper.findAll('.apiDesignFindings__group h4').map((h) => h.text())).toEqual(['Errors (1)', 'To check by hand (1)'])
		expect(wrapper.find('[data-testid="api-design-manual"] a').attributes('href')).toBe('https://example.org/oas')
	})

	it('says no rule found a problem after a clean check', async () => {
		http.post.mockResolvedValue({ data: { findings: [], manual: [] } })
		const wrapper = await panel()

		await wrapper.find('[data-testid="api-design-check"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="api-design-empty"]').text()).toBe('No rule found a problem.')
	})

	it('shows the server\'s refusal when the check fails and keeps the findings it had', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'The API product could not be checked: no description' } } })
		const wrapper = await panel()

		await wrapper.find('[data-testid="api-design-check"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="api-design-error"]').text()).toBe('The API product could not be checked: no description')
		expect(wrapper.findAll('.apiDesignFindings__group')).toHaveLength(2)
	})

	it('opens the waive dialog on the finding and shows it waived once the server answers', async () => {
		const wrapper = await panel()

		await wrapper.find('[data-testid="api-design-waive-f1"]').trigger('click')
		const dialog = wrapper.findComponent(WaiveDesignFindingDialog)
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('finding')).toEqual(ERROR)

		dialog.vm.$emit('waived', { ...ERROR, status: 'waived', waivedBy: 'admin', waiveReason: 'Accepted' })
		await flushPromises()

		expect(wrapper.findComponent(WaiveDesignFindingDialog).props('open')).toBe(false)
		expect(wrapper.find('[data-testid="api-design-waive-f1"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Waived by admin: Accepted')
	})
})

describe('WaiveDesignFindingDialog', () => {
	it('sends the trimmed reason and passes on the waived finding', async () => {
		http.post.mockResolvedValue({ data: { ...ERROR, status: 'waived' } })
		const wrapper = mount(WaiveDesignFindingDialog, { props: { open: true, finding: ERROR } })

		expect(wrapper.find('[data-testid="waive-finding-submit"]').attributes('disabled')).toBeDefined()
		await wrapper.find('.NcTextField').setValue('  Accepted risk  ')
		await wrapper.find('[data-testid="waive-finding-submit"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/products/design-findings/f1/waive', { reason: 'Accepted risk' })
		expect(wrapper.emitted('waived')[0][0]).toEqual({ ...ERROR, status: 'waived' })
	})

	it('shows the server\'s refusal and stays open', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'A waiver needs a reason.' } } })
		const wrapper = mount(WaiveDesignFindingDialog, { props: { open: true, finding: ERROR } })

		await wrapper.find('.NcTextField').setValue('x')
		await wrapper.find('[data-testid="waive-finding-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="waive-finding-error"]').text()).toBe('A waiver needs a reason.')
		expect(wrapper.emitted('waived')).toBeUndefined()
	})
})

describe('apiDesignFindings helpers', () => {
	it('groups by severity, errors first, unknown severities as notes, empty groups left out', () => {
		expect(groupBySeverity([{ severity: 'info' }, { severity: 'error' }, { severity: 'odd' }])).toEqual([
			{ severity: 'error', findings: [{ severity: 'error' }] },
			{ severity: 'info', findings: [{ severity: 'info' }, { severity: 'odd' }] },
		])
		expect(groupBySeverity(null)).toEqual([])
	})

	it('counts only an open error as blocking', () => {
		expect(isBlocking({ severity: 'error' })).toBe(true)
		expect(isBlocking({ severity: 'error', status: 'waived' })).toBe(false)
		expect(isBlocking({ severity: 'warning', status: 'open' })).toBe(false)
	})

	it('reads the id from id, uuid or @self, and replaces only the matching finding', () => {
		expect(findingId({ uuid: 'u' })).toBe('u')
		expect(findingId({ '@self': { id: 's' } })).toBe('s')
		expect(findingId({})).toBe('')
		expect(replaceFinding([ERROR, WARNING], { id: 'f1', status: 'waived' })).toEqual([{ ...ERROR, status: 'waived' }, WARNING])
		expect(replaceFinding([ERROR], {})).toEqual([ERROR])
	})
})
