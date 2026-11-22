// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payments review: a page over payment_intent with no create and no edit,
 * a "Refresh status" row action that posts to the refresh endpoint, and the
 * provider step of a payment source, which picks a provider from the bindings
 * the server lists and checks the key without ever showing it.
 *
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PaymentProviderFields from '@/modals/v2/PaymentProviderFields.vue'
import SourceFormFields from '@/modals/v2/SourceFormFields.vue'
import { refreshPaymentHandler } from '@/handlers/actionHandlers.js'

const { get, post, showError, showSuccess } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'label', 'inputLabel', 'options', 'id', 'inputId'],
			emits: ['update:modelValue'],
			render() {
				return h('div', {
					class: name,
					'data-id': this.id || this.inputId,
				})
			},
		})
	return {
		NcTextField: field('NcTextField'),
		NcSelect: field('NcSelect'),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
		NcButton: defineComponent({
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({ name: 'CnFieldHelper', render: () => h('span') }),
	}
})

const root = join(__dirname, '..', '..')
const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const PROVIDERS = {
	data: {
		providers: [
			{ id: 'log', label: 'Log (sandbox, charges nobody)', needsKey: false },
			{ id: 'mollie', label: 'Mollie', needsKey: true },
		],
	},
}

const MOLLIE = {
	provider: 'mollie',
	authentication: { credentialRef: { credentialId: 'cred-1' } },
	mockStatuses: {},
}

beforeEach(() => {
	get.mockReset()
	post.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
	get.mockResolvedValue(PROVIDERS)
})

describe('payments page', () => {
	const register = read('lib/Settings/integriq_register.json')
	const merged = buildManifest(
		read('src/manifest.json'),
		[read('src/manifest.d/payments.json')],
		read('src/menu-layout.json'),
	)
	const page = merged.pages.find((candidate) => candidate.id === 'Payments')

	it('lists payment_intent with status, outcome and source, and offers no create or edit', () => {
		expect(page.config.schema).toBe('payment_intent')
		expect(page.config.columns.map((column) => column.key)).toEqual([
			'createdAt',
			'description',
			'amountValue',
			'amountCurrency',
			'provider',
			'paymentStatus',
			'lastOutcome',
			'sourceSlug',
		])
		expect(page.config.showAdd).toBe(false)
		expect(page.config.showEditAction).toBe(false)
		expect(page.config.showDeleteAction).toBe(false)
	})

	it('filters on status and only reads columns the schema has', () => {
		const properties = register.components.schemas.payment_intent.properties
		expect(page.config.quickFiltersFrom.field).toBe('paymentStatus')
		for (const column of page.config.columns) {
			expect(properties[column.key], column.key).toBeDefined()
		}
	})

	it('colours every status the schema can hold, and only paid is green', () => {
		const allowed = register.components.schemas.payment_intent.properties.paymentStatus.enum
		const status = page.config.columns.find((column) => column.key === 'paymentStatus')
		expect(Object.keys(status.widgetProps.colorMap).sort()).toEqual([...allowed].sort())
		const green = Object.keys(status.widgetProps.colorMap).filter(
			(key) => status.widgetProps.colorMap[key] === 'success',
		)
		expect(green).toEqual(['paid'])
	})

	it('wires "Refresh status" to a handler the registry exports', () => {
		const action = page.config.actions.find((candidate) => candidate.id === 'refresh-payment-status')
		expect(action.handler).toBe('refreshPaymentHandler')
		const registrySource = readFileSync(join(root, 'src/registry.js'), 'utf8')
		const exported = registrySource.slice(registrySource.indexOf('export default'))
		expect(exported).toMatch(/^\s*refreshPaymentHandler,$/m)
	})
})

describe('refresh status', () => {
	it('posts to the refresh endpoint of the row and reports the status', async () => {
		post.mockResolvedValue({ data: { result: 'applied', outcome: 'captured', paymentStatus: 'paid' } })

		const result = await refreshPaymentHandler({ item: { id: 'pi 1' } })

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/payments/pi%201/refresh')
		expect(result.paymentStatus).toBe('paid')
		expect(showSuccess).toHaveBeenCalledWith('The provider reports this payment as paid.')
	})

	it('shows the server reason when the refresh is refused', async () => {
		post.mockRejectedValue({ response: { data: { message: 'Unknown payment provider: "Mollie".' } } })

		expect(await refreshPaymentHandler({ item: { id: 'pi-1' } })).toBeNull()
		expect(showError).toHaveBeenCalledWith(
			'The payment status was not refreshed: Unknown payment provider: "Mollie".',
		)
	})

	it('posts nothing for a row without an id', async () => {
		expect(await refreshPaymentHandler({ item: {} })).toBeNull()
		expect(post).not.toHaveBeenCalled()
	})
})

describe('payment provider step', () => {
	it('shows only on a payment source', async () => {
		const mountWith = (type) =>
			mount(SourceFormFields, {
				props: { formData: { type, configuration: {} }, updateField: vi.fn() },
			})
		expect(mountWith('payment').findComponent(PaymentProviderFields).exists()).toBe(true)
		expect(mountWith('api').findComponent(PaymentProviderFields).exists()).toBe(false)
		await flushPromises()
	})

	it('builds the picker from the server list and writes the pick, keeping other keys', async () => {
		const wrapper = mount(PaymentProviderFields, { props: { configuration: { mockStatuses: { a: 'paid' } } } })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/payments/providers')
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('options').map((option) => option.id)).toEqual(['log', 'mollie'])
		expect(select.props('modelValue').id).toBe('log')

		select.vm.$emit('update:modelValue', { id: 'mollie' })
		expect(wrapper.emitted('update:configuration')[0][0]).toEqual({
			mockStatuses: { a: 'paid' },
			provider: 'mollie',
		})
	})

	it('names a provider value this instance does not have', async () => {
		const wrapper = mount(PaymentProviderFields, { props: { configuration: { provider: 'Mollie' } } })
		await flushPromises()

		expect(wrapper.text()).toContain('"Mollie" is not a payment provider this instance has.')
	})

	it('checks the draft configuration and reports an accepted test key', async () => {
		post.mockResolvedValue({ data: { provider: 'mollie', ok: true, mode: 'test', profile: 'School', message: null } })
		const wrapper = mount(PaymentProviderFields, { props: { configuration: MOLLIE } })
		await flushPromises()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/payments/providers/check', { configuration: MOLLIE })
		const result = wrapper.find('[role="status"]')
		expect(result.text()).toBe('The key is accepted. It is a test key: payments are not real.')
		expect(result.text()).not.toContain('cred-1')
	})

	it('reports a refused key with the reason', async () => {
		post.mockResolvedValue({ data: { provider: 'mollie', ok: false, mode: null, message: 'Mollie responded with HTTP 401.' } })
		const wrapper = mount(PaymentProviderFields, { props: { configuration: MOLLIE } })
		await flushPromises()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="status"]').text()).toBe('The provider refused: Mollie responded with HTTP 401.')
	})
})
