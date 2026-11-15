// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ApiProductDetail against a routed axios fake: the four independent loads,
 * what each panel renders from them, the endpoint and tier edits PATCHed as
 * whole replacement values, and approve/reject on pending subscriptions with
 * their toasts and failure messages.
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApiProductDetail from '@/views/ApiProducts/ApiProductDetail.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const box = (name) =>
		defineComponent({
			name,
			props: ['name', 'description', 'size'],
			render() {
				return h('div', { class: name }, [
					this.name,
					this.description,
					this.$slots.default?.(),
				])
			},
		})
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled', 'type', 'ariaLabel'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{
						class: 'NcButton',
						type: this.type || 'button',
						disabled: this.disabled || undefined,
						onClick: () => this.$emit('click'),
					},
					this.$slots.default?.(),
				)
			},
		}),
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: ['modelValue', 'options', 'loading', 'multiple'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'NcSelect' })
			},
		}),
		NcEmptyContent: box('NcEmptyContent'),
		NcLoadingIcon: box('NcLoadingIcon'),
	}
})

const PRODUCT_URL = '/index.php/apps/openregister/api/objects/integriq/api_product/p1'
const ENDPOINTS_URL = '/index.php/apps/openregister/api/objects/integriq/endpoint'
const ANALYTICS_URL = '/index.php/apps/integriq/api/products/p1/analytics'
const SUBS_URL = '/index.php/apps/openregister/api/objects/integriq/api_product_subscription'

const PRODUCT = {
	name: 'Zaken API',
	status: 'deprecated',
	sunsetDate: '2027-01-01',
	endpoints: ['e1', 'gone'],
	tiers: {
		gold: {
			rateLimit: { requestsPerWindow: 100, windowSeconds: 60 },
			requiresApproval: true,
			approverGroup: 'admins',
		},
		free: {},
	},
}
const ENDPOINTS = [
	{ id: 'e1', name: 'Cases' },
	{ uuid: 'e2', endpoint: '/documents' },
	{ id: 'e3' },
]
const ANALYTICS = {
	requestCount: 42,
	errorRate: 0.0125,
	latency: { p50: 12.4, p95: 80.6 },
}
const SUBS = [
	{ id: 's1', consumer: 'Gemeente A', tier: 'gold', status: 'pending_approval' },
	{ uuid: 's2', consumer: 'Gemeente B', tier: 'free', status: 'active' },
]

/**
 * Route GETs to canned responses; a value that is an Error rejects.
 *
 * @param {object} routes url → data or Error
 */
function serve(routes) {
	http.get.mockImplementation(async (url) => {
		const value = routes[url]
		if (value instanceof Error) throw value
		if (value === undefined) throw new Error(`unexpected GET ${url}`)
		return { data: typeof value === 'function' ? value() : value }
	})
}

/**
 * Mount the page on product p1 and let every load settle.
 *
 * @return {Promise<{wrapper: object, push: Function}>} the page and its router push
 */
async function mountPage() {
	const push = vi.fn()
	const wrapper = mount(ApiProductDetail, {
		global: { mocks: { $route: { params: { id: 'p1' } }, $router: { push } } },
	})
	await flushPromises()
	return { wrapper, push }
}

/**
 * The button with the given text, optionally inside a selector.
 *
 * @param {object} wrapper the page
 * @param {string} text the button text
 * @param {string} within a selector to search inside
 * @return {object} the button wrapper
 */
function button(wrapper, text, within = '') {
	const scope = within ? wrapper.find(within) : wrapper
	return scope.findAll('button.NcButton').find((b) => b.text() === text)
}

/**
 * The texts of the list items of one section.
 *
 * @param {object} wrapper the page
 * @param {number} index list position (0 endpoints, 1 subscriptions)
 * @return {string[]} the item texts
 */
function listItems(wrapper, index) {
	return wrapper
		.findAll('ul.apiProductDetail__list')
		[index].findAll('li')
		.map((li) => li.text().replace(/\s+/g, ' ').trim())
}

beforeEach(() => {
	for (const fn of [...Object.values(http), ...Object.values(toast)]) fn.mockReset()
	serve({
		[PRODUCT_URL]: () => structuredClone(PRODUCT),
		[ENDPOINTS_URL]: { results: ENDPOINTS },
		[ANALYTICS_URL]: ANALYTICS,
		[SUBS_URL]: { results: SUBS },
	})
	http.patch.mockResolvedValue({ data: {} })
	http.post.mockResolvedValue({ data: {} })
})

describe('ApiProductDetail loading', () => {
	it('requests the product, endpoints, analytics and its own subscriptions', async () => {
		await mountPage()
		expect(http.get.mock.calls).toEqual(
			expect.arrayContaining([
				[PRODUCT_URL],
				[ENDPOINTS_URL, { params: { _limit: 500 } }],
				[ANALYTICS_URL],
				[SUBS_URL, { params: { product: 'p1', _limit: 100 } }],
			]),
		)
		expect(http.get).toHaveBeenCalledTimes(4)
	})

	it('shows the product header with the deprecation notice', async () => {
		const { wrapper } = await mountPage()
		expect(wrapper.find('h2').text()).toBe('Zaken API')
		const badge = wrapper.find('.apiProductDetail__badge')
		expect(badge.text()).toBe('deprecated')
		expect(badge.classes()).toContain('apiProductDetail__badge--deprecated')
		expect(wrapper.find('.apiProductDetail__sunsetNotice').text()).toBe(
			'Deprecated — sunset 2027-01-01',
		)
	})

	it('shows a dash for a deprecated product without a sunset date', async () => {
		serve({
			[PRODUCT_URL]: { name: 'X', status: 'deprecated' },
			[ENDPOINTS_URL]: [],
			[ANALYTICS_URL]: ANALYTICS,
			[SUBS_URL]: [],
		})
		const { wrapper } = await mountPage()
		expect(wrapper.find('.apiProductDetail__sunsetNotice').text()).toBe('Deprecated — sunset —')
	})

	it('shows no notice for an active product', async () => {
		serve({
			[PRODUCT_URL]: { name: 'X', status: 'active' },
			[ENDPOINTS_URL]: [],
			[ANALYTICS_URL]: ANALYTICS,
			[SUBS_URL]: [],
		})
		const { wrapper } = await mountPage()
		expect(wrapper.find('.apiProductDetail__sunsetNotice').exists()).toBe(false)
	})

	it('shows the not-found state when the product fails to load', async () => {
		serve({
			[PRODUCT_URL]: new Error('404'),
			[ENDPOINTS_URL]: [],
			[ANALYTICS_URL]: ANALYTICS,
			[SUBS_URL]: [],
		})
		const { wrapper } = await mountPage()
		expect(wrapper.find('.apiProductDetail__body').exists()).toBe(false)
		expect(wrapper.find('.NcEmptyContent').text()).toContain('API product not found')
	})

	it('shows the spinner while the product loads', () => {
		http.get.mockImplementation(() => new Promise(() => {}))
		const wrapper = mount(ApiProductDetail, {
			global: { mocks: { $route: { params: { id: 'p1' } }, $router: { push: vi.fn() } } },
		})
		return wrapper.vm.$nextTick().then(() => {
			expect(wrapper.find('.apiProductDetail__loading').exists()).toBe(true)
			expect(wrapper.find('.NcEmptyContent').exists()).toBe(false)
		})
	})

	it('goes back to the product list', async () => {
		const { wrapper, push } = await mountPage()
		await button(wrapper, 'Back to API Products').trigger('click')
		expect(push).toHaveBeenCalledWith('/products')
	})
})

describe('ApiProductDetail endpoints', () => {
	it('labels attached endpoints by name, falling back to the raw id', async () => {
		const { wrapper } = await mountPage()
		expect(listItems(wrapper, 0)).toEqual(['Cases Remove', 'gone Remove'])
	})

	it('offers only unattached endpoints, by name, path or id', async () => {
		const { wrapper } = await mountPage()
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 'e2', label: '/documents' },
			{ id: 'e3', label: 'e3' },
		])
	})

	it('accepts a plain array of endpoints', async () => {
		serve({
			[PRODUCT_URL]: { name: 'X', endpoints: [] },
			[ENDPOINTS_URL]: [{ id: 7, name: 'Seven' }],
			[ANALYTICS_URL]: ANALYTICS,
			[SUBS_URL]: [],
		})
		const { wrapper } = await mountPage()
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: '7', label: 'Seven' },
		])
		expect(listItems(wrapper, 0)).toEqual(['No endpoints attached yet.'])
	})

	it('treats an unusable endpoint response or failure as no endpoints', async () => {
		serve({
			[PRODUCT_URL]: { name: 'X', endpoints: 'nope', tiers: 'nope' },
			[ENDPOINTS_URL]: { total: 0 },
			[ANALYTICS_URL]: ANALYTICS,
			[SUBS_URL]: [],
		})
		let { wrapper } = await mountPage()
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([])
		expect(wrapper.findAll('tbody tr')).toHaveLength(0)
		serve({
			[PRODUCT_URL]: { name: 'X' },
			[ENDPOINTS_URL]: new Error('500'),
			[ANALYTICS_URL]: ANALYTICS,
			[SUBS_URL]: [],
		})
		;({ wrapper } = await mountPage())
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([])
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('loading')).toBe(false)
	})

	it('adds the selected endpoints once each, then reloads and clears the pick', async () => {
		const { wrapper } = await mountPage()
		const add = () => button(wrapper, 'Add', '.apiProductDetail__section')
		expect(add().attributes('disabled')).toBeDefined()
		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', [{ id: 'e2' }, { id: 'e1' }])
		await wrapper.vm.$nextTick()
		expect(add().attributes('disabled')).toBeUndefined()
		http.get.mockClear()
		await add().trigger('click')
		await flushPromises()
		expect(http.patch).toHaveBeenCalledWith(PRODUCT_URL, { endpoints: ['e1', 'gone', 'e2'] })
		expect(http.get).toHaveBeenCalledWith(PRODUCT_URL)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual([])
		expect(toast.showSuccess).toHaveBeenCalledWith('Endpoint(s) added')
	})

	it('removes one endpoint by its id', async () => {
		const { wrapper } = await mountPage()
		await wrapper.findAll('ul.apiProductDetail__list')[0].find('button').trigger('click')
		await flushPromises()
		expect(http.patch).toHaveBeenCalledWith(PRODUCT_URL, { endpoints: ['gone'] })
	})

	it('reports a failed save with the server message', async () => {
		http.patch.mockRejectedValue({ response: { data: { message: 'Locked' } } })
		const { wrapper } = await mountPage()
		await wrapper.vm.removeEndpoint('e1')
		expect(toast.showError).toHaveBeenCalledWith('Failed to update API product: Locked')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('reports a failed save with the transport message or none', async () => {
		const { wrapper } = await mountPage()
		http.patch.mockRejectedValueOnce(new Error('Network Error'))
		await wrapper.vm.removeEndpoint('e1')
		http.patch.mockRejectedValueOnce({})
		await wrapper.vm.removeEndpoint('e1')
		expect(toast.showError.mock.calls).toEqual([
			['Failed to update API product: Network Error'],
			['Failed to update API product'],
		])
	})
})

describe('ApiProductDetail tiers', () => {
	it('lists each tier with dashes for what it lacks', async () => {
		const { wrapper } = await mountPage()
		const rows = wrapper
			.findAll('tbody tr')
			.map((tr) => tr.findAll('td').slice(0, 5).map((td) => td.text()))
		expect(rows).toEqual([
			['gold', '100', '60', 'Yes', 'admins'],
			['free', '—', '—', 'No', '—'],
		])
	})

	it('adds a tier from the form and resets it', async () => {
		const { wrapper } = await mountPage()
		const inputs = wrapper.findAll('.apiProductDetail__tierForm input')
		await inputs[0].setValue('silver')
		await inputs[1].setValue('10')
		await inputs[2].setValue('30')
		await inputs[3].setValue(true)
		await inputs[4].setValue('ops')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(http.patch).toHaveBeenCalledWith(PRODUCT_URL, {
			tiers: {
				...PRODUCT.tiers,
				silver: {
					rateLimit: { requestsPerWindow: 10, windowSeconds: 30 },
					requiresApproval: true,
					approverGroup: 'ops',
				},
			},
		})
		expect(wrapper.find('.apiProductDetail__tierForm input').element.value).toBe('')
		expect(toast.showSuccess).toHaveBeenCalledWith('Tier added')
	})

	it('stores a bare tier without limits or a group', async () => {
		const { wrapper } = await mountPage()
		await wrapper.find('.apiProductDetail__tierForm input').setValue('basic')
		await wrapper.vm.addTier()
		expect(http.patch.mock.calls[0][1].tiers.basic).toEqual({
			rateLimit: { requestsPerWindow: undefined, windowSeconds: undefined },
			requiresApproval: false,
			approverGroup: undefined,
		})
	})

	it('saves nothing for a tier without a name', async () => {
		const { wrapper } = await mountPage()
		expect(button(wrapper, 'Add tier').attributes('disabled')).toBeDefined()
		await wrapper.vm.addTier()
		expect(http.patch).not.toHaveBeenCalled()
	})

	it('removes one tier', async () => {
		const { wrapper } = await mountPage()
		await wrapper.find('tbody tr').find('button').trigger('click')
		await flushPromises()
		expect(http.patch).toHaveBeenCalledWith(PRODUCT_URL, { tiers: { free: {} } })
	})
})

describe('ApiProductDetail analytics', () => {
	it('formats the error rate and latency percentiles', async () => {
		const { wrapper } = await mountPage()
		const values = wrapper.findAll('[data-testid="analytics-summary"] dd').map((d) => d.text())
		expect(values).toEqual(['42', '1.25%', '12 ms', '81 ms', '—'])
	})

	it('shows dashes when the analytics carry no numbers', async () => {
		serve({
			[PRODUCT_URL]: PRODUCT,
			[ENDPOINTS_URL]: [],
			[ANALYTICS_URL]: { requestCount: 0 },
			[SUBS_URL]: [],
		})
		const { wrapper } = await mountPage()
		const values = wrapper.findAll('[data-testid="analytics-summary"] dd').map((d) => d.text())
		expect(values).toEqual(['0', '—', '—', '—', '—'])
	})

	it('hides the panel when analytics fail, leaving the rest usable', async () => {
		serve({
			[PRODUCT_URL]: PRODUCT,
			[ENDPOINTS_URL]: { results: ENDPOINTS },
			[ANALYTICS_URL]: new Error('403'),
			[SUBS_URL]: { results: SUBS },
		})
		const { wrapper } = await mountPage()
		expect(wrapper.find('[data-testid="analytics-summary"]').exists()).toBe(false)
		expect(wrapper.find('h2').text()).toBe('Zaken API')
		expect(listItems(wrapper, 1)).toHaveLength(2)
	})
})

describe('ApiProductDetail subscriptions', () => {
	it('offers approve and reject on pending subscriptions only', async () => {
		const { wrapper } = await mountPage()
		const items = wrapper.findAll('ul.apiProductDetail__list')[1].findAll('li')
		expect(items[0].findAll('button').map((b) => b.text())).toEqual(['Approve', 'Reject'])
		expect(items[1].findAll('button')).toHaveLength(0)
		expect(items[1].text()).toBe('Gemeente B — free — active')
	})

	it('approves through the gate and reloads the list', async () => {
		const { wrapper } = await mountPage()
		http.get.mockClear()
		await button(wrapper, 'Approve').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/products/subscriptions/s1/approve',
		)
		expect(toast.showSuccess).toHaveBeenCalledWith('Subscription approved')
		expect(http.get).toHaveBeenCalledWith(SUBS_URL, { params: { product: 'p1', _limit: 100 } })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('rejects through the gate with a fixed comment, by uuid when there is no id', async () => {
		const { wrapper } = await mountPage()
		await wrapper.vm.rejectSubscription({ uuid: 'u9' })
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/products/subscriptions/u9/reject',
			{ comment: 'Rejected from API Products UI' },
		)
		expect(toast.showSuccess).toHaveBeenCalledWith('Subscription rejected')
	})

	it('reports gate failures with the server error, and no reload', async () => {
		const { wrapper } = await mountPage()
		http.get.mockClear()
		http.post.mockRejectedValue({ response: { data: { error: 'Not an approver' } } })
		await wrapper.vm.approveSubscription({ id: 's1' })
		await wrapper.vm.rejectSubscription({ id: 's1' })
		expect(toast.showError.mock.calls).toEqual([
			['Approve failed: Not an approver'],
			['Reject failed: Not an approver'],
		])
		expect(toast.showSuccess).not.toHaveBeenCalled()
		expect(http.get).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reports gate failures without a detail', async () => {
		const { wrapper } = await mountPage()
		http.post.mockRejectedValue({})
		await wrapper.vm.approveSubscription({ id: 's1' })
		await wrapper.vm.rejectSubscription({ id: 's1' })
		expect(toast.showError.mock.calls).toEqual([['Approve failed'], ['Reject failed']])
	})

	it('shows the empty state for a plain empty list, a bad shape or a failure', async () => {
		for (const subs of [[], { total: 0 }, new Error('500')]) {
			serve({
				[PRODUCT_URL]: PRODUCT,
				[ENDPOINTS_URL]: [],
				[ANALYTICS_URL]: ANALYTICS,
				[SUBS_URL]: subs,
			})
			const { wrapper } = await mountPage()
			expect(listItems(wrapper, 1)).toEqual(['No subscriptions yet.'])
		}
	})
})
