// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * NotificatiesAbonnementenPage against an axios fake: the list load and the
 * table or empty state, kanaal names and the last error column, opening the
 * form for create (cleared) and edit, the DELETE by id or uuid with its
 * reload and toasts, and the reload after the form saves.
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificatiesAbonnementenPage from '@/views/NotificatiesAbonnement/NotificatiesAbonnementenPage.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant'],
			emits: ['click'],
			render() {
				return h('button', { class: 'NcButton', onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			render() {
				return h('div', { class: 'NcEmptyContent' }, [this.name, ' ', this.description])
			},
		}),
		NcLoadingIcon: defineComponent({
			name: 'NcLoadingIcon',
			props: ['size'],
			render() {
				return h('div', { class: 'NcLoadingIcon' })
			},
		}),
	}
})
vi.mock('@/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name: 'NotificatiesAbonnementForm',
			props: ['open', 'abonnement'],
			emits: ['close', 'saved'],
			render() {
				return h('div', { class: 'form' })
			},
		}),
	}
})

const LIST_URL = '/index.php/apps/integriq/api/notificaties/abonnementen'

const ROWS = [
	{ id: 'ab-1', name: 'Zaken', status: 'active', kanalen: [{ naam: 'zaken' }, { naam: '' }, { naam: 'documenten' }] },
	{ uuid: 'uu-2', status: 'error', lastError: 'remote 500', kanalen: 'not-an-array' },
	{ id: 'ab-3', name: 'Besluiten', status: 'error', kanalen: [] },
]

/**
 * Mount the page and let the initial load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPage() {
	const wrapper = mount(NotificatiesAbonnementenPage, { global: { stubs: { BellRingOutline: true } } })
	await flushPromises()
	return wrapper
}

/**
 * The form component inside the page.
 *
 * @param {object} wrapper The page wrapper.
 * @return {object} The form wrapper.
 */
function form(wrapper) {
	return wrapper.findComponent({ name: 'NotificatiesAbonnementForm' })
}

describe('NotificatiesAbonnementenPage', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.delete.mockReset()
		toast.showError.mockReset()
		toast.showSuccess.mockReset()
	})

	it('loads the abonnementen on mount and renders name, kanalen, status and last error', async () => {
		http.get.mockResolvedValue({ data: { results: ROWS } })
		const wrapper = await mountPage()

		expect(http.get).toHaveBeenCalledTimes(1)
		expect(http.get).toHaveBeenCalledWith(LIST_URL)
		const cells = wrapper.findAll('[data-testid="abonnementen-table"] tbody tr')
			.map((tr) => tr.findAll('td').slice(0, 4).map((td) => td.text()))
		expect(cells).toEqual([
			['Zaken', 'zaken, documenten', 'active', '—'],
			['—', '—', 'error', 'remote 500'],
			['Besluiten', '—', 'error', '—'],
		])
		expect(wrapper.find('.notificatiesAbonnementen__badge').classes()).toContain('notificatiesAbonnementen__badge--active')
	})

	it('shows the empty state when there are no abonnementen', async () => {
		http.get.mockResolvedValue({ data: {} })
		const wrapper = await mountPage()

		expect(wrapper.find('[data-testid="empty-state"]').text()).toContain('No abonnementen yet')
		expect(wrapper.find('[data-testid="abonnementen-table"]').exists()).toBe(false)
	})

	it('toasts a failed load and keeps the previous rows', async () => {
		http.get.mockResolvedValueOnce({ data: { results: ROWS } })
		const wrapper = await mountPage()

		http.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.reload()
		expect(toast.showError).toHaveBeenCalledWith('Failed to load abonnementen')
		expect(wrapper.vm.rows).toEqual(ROWS)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('opens the form for an edit with the row and for a create with no abonnement', async () => {
		http.get.mockResolvedValue({ data: { results: ROWS } })
		const wrapper = await mountPage()
		expect(form(wrapper).props('open')).toBe(false)

		await wrapper.findAll('tbody tr')[0].findAll('button').find((b) => b.text() === 'Edit').trigger('click')
		expect(form(wrapper).props('open')).toBe(true)
		expect(form(wrapper).props('abonnement')).toEqual(ROWS[0])

		await form(wrapper).vm.$emit('close')
		expect(form(wrapper).props('open')).toBe(false)

		await wrapper.find('[data-testid="add-abonnement"]').trigger('click')
		expect(form(wrapper).props('open')).toBe(true)
		expect(form(wrapper).props('abonnement')).toBeNull()
	})

	it('closes the form and reloads from the server after a save', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await mountPage()
		wrapper.vm.openCreate()

		await form(wrapper).vm.$emit('saved')
		await flushPromises()
		expect(wrapper.vm.form.open).toBe(false)
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('deletes a row by id, toasts and reloads', async () => {
		http.get.mockResolvedValue({ data: { results: ROWS } })
		http.delete.mockResolvedValue({})
		const wrapper = await mountPage()

		await wrapper.findAll('tbody tr')[0].findAll('button').find((b) => b.text() === 'Delete').trigger('click')
		await flushPromises()
		expect(http.delete).toHaveBeenCalledWith(`${LIST_URL}/ab-1`)
		expect(toast.showSuccess).toHaveBeenCalledWith('Abonnement deleted')
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('deletes a row without id by its uuid', async () => {
		http.get.mockResolvedValue({ data: { results: ROWS } })
		http.delete.mockResolvedValue({})
		const wrapper = await mountPage()

		await wrapper.vm.remove(ROWS[1])
		expect(http.delete).toHaveBeenCalledWith(`${LIST_URL}/uu-2`)
	})

	it('ignores a row with neither id nor uuid', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await mountPage()

		await wrapper.vm.remove({ name: 'orphan' })
		expect(http.delete).not.toHaveBeenCalled()
		expect(toast.showSuccess).not.toHaveBeenCalled()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	for (const [label, err, expected] of [
		['server error text', { response: { data: { error: 'in use' } } }, ': in use'],
		['network message', { message: 'Network Error' }, ': Network Error'],
		['bare failure', {}, ''],
	]) {
		it(`toasts a failed delete with the ${label} and does not reload`, async () => {
			http.get.mockResolvedValue({ data: { results: ROWS } })
			http.delete.mockRejectedValue(err)
			const wrapper = await mountPage()

			await wrapper.vm.remove(ROWS[0])
			expect(toast.showError).toHaveBeenCalledWith(`Failed to delete abonnement${expected}`)
			expect(toast.showSuccess).not.toHaveBeenCalled()
			expect(http.get).toHaveBeenCalledTimes(1)
		})
	}
})
