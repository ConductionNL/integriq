// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opening the Notificaties abonnement form (NotificatiesAbonnementForm),
 * driven on the real component: the form is filled from the abonnement it
 * edits or emptied for a new one each time it opens, the sources are loaded
 * once, and the source and kanaal pickers write into the model. Saving is
 * covered by abonnementFormSaveGolden.spec.js.
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificatiesAbonnementForm from '@/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue'

const get = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get, put: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve; the modal renders its slot, the fields are stubs.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) => defineComponent({ name, inheritAttrs: false, render: () => h('div', { class: name }) })
	return {
		NcModal: defineComponent({ name: 'NcModal', render() { return h('div', this.$slots.default?.()) } }),
		NcButton: stub('NcButton'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

const EDITED = {
	id: 7,
	name: 'Zaken',
	sourceId: 's2',
	authHeaderName: 'X-Api-Key',
	authScheme: 'Token',
	kanalen: [{ naam: 'zaken' }, { naam: '' }, { naam: 'besluiten', filters: { a: 1 } }],
}

/**
 * Mount the form.
 *
 * @param {object} props the form props
 * @return {object} the wrapper
 */
function mountForm(props) {
	return mount(NotificatiesAbonnementForm, { props })
}

beforeEach(() => {
	get.mockReset()
	get.mockResolvedValue({ data: { results: [{ id: 's1', name: 'ZGW' }, { uuid: 's2', name: 'Open Zaak' }, { id: 's3' }] } })
})

describe('NotificatiesAbonnementForm opening', () => {
	it('loads nothing while it is closed', () => {
		const wrapper = mountForm({ open: false, abonnement: EDITED })
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.vm.model).toEqual({ name: '', sourceId: '', authHeaderName: 'Authorization', authScheme: '' })
	})

	it('fills the form from the abonnement it edits and loads the sources', async () => {
		const wrapper = mountForm({ open: true, abonnement: EDITED })
		await flushPromises()
		expect(wrapper.vm.isEdit).toBe(true)
		expect(wrapper.vm.model).toEqual({ name: 'Zaken', sourceId: 's2', authHeaderName: 'X-Api-Key', authScheme: 'Token' })
		expect(wrapper.vm.kanaalNames).toEqual(['zaken', 'besluiten'])
		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/source', { params: { _limit: 500 } })
		expect(wrapper.vm.sourceOptions).toEqual([
			{ id: 's1', label: 'ZGW' },
			{ id: 's2', label: 'Open Zaak' },
			{ id: 's3', label: 's3' },
		])
		expect(wrapper.vm.selectedSource).toEqual({ id: 's2', label: 'Open Zaak' })
		expect(wrapper.vm.sourcesLoading).toBe(false)
	})

	it('fills in the defaults an older abonnement lacks', async () => {
		const wrapper = mountForm({ open: true, abonnement: { uuid: 'u-1', kanalen: 'none' } })
		await flushPromises()
		expect(wrapper.vm.isEdit).toBe(true)
		expect(wrapper.vm.model).toEqual({ name: '', sourceId: '', authHeaderName: 'Authorization', authScheme: '' })
		expect(wrapper.vm.kanaalNames).toEqual([])
	})

	it('a new abonnement opens empty', async () => {
		const wrapper = mountForm({ open: true, abonnement: null })
		await flushPromises()
		expect(wrapper.vm.isEdit).toBe(false)
		expect(wrapper.vm.model).toEqual({ name: '', sourceId: '', authHeaderName: 'Authorization', authScheme: '' })
		expect(wrapper.vm.kanaalNames).toEqual([])
		expect(wrapper.vm.selectedSource).toBeNull()
		expect(mountForm({ open: false, abonnement: { name: 'no id' } }).vm.isEdit).toBe(false)
	})

	it('reopening resets the edits and errors but loads the sources only once', async () => {
		const wrapper = mountForm({ open: true, abonnement: EDITED })
		await flushPromises()
		wrapper.vm.model.name = 'Changed'
		wrapper.vm.errors = { name: 'Name is required' }
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(wrapper.vm.model.name).toBe('Zaken')
		expect(wrapper.vm.errors).toEqual({})
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('reads a bare list of sources, and anything else as none', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 9, name: 'Bare' }] })
		const bare = mountForm({ open: true })
		await flushPromises()
		expect(bare.vm.sourceOptions).toEqual([{ id: '9', label: 'Bare' }])

		get.mockResolvedValueOnce({ data: { total: 0 } })
		const none = mountForm({ open: true })
		await flushPromises()
		expect(none.vm.sourceOptions).toEqual([])
	})

	it('a failed source load leaves no sources, and a stored one still shows', async () => {
		get.mockRejectedValueOnce(new Error('500'))
		const wrapper = mountForm({ open: true, abonnement: EDITED })
		await flushPromises()
		expect(wrapper.vm.sourceOptions).toEqual([])
		expect(wrapper.vm.sourcesLoading).toBe(false)
		expect(wrapper.vm.selectedSource).toEqual({ id: 's2', label: 's2' })
	})
})

describe('NotificatiesAbonnementForm pickers', () => {
	it('a picked source is stored by id, a cleared one as empty', () => {
		const wrapper = mountForm({ open: false })
		wrapper.vm.onSourcePick({ id: 's1', label: 'ZGW' })
		expect(wrapper.vm.model.sourceId).toBe('s1')
		wrapper.vm.onSourcePick(null)
		expect(wrapper.vm.model.sourceId).toBe('')
	})

	it('the kanalen are the picked list, anything else none', () => {
		const wrapper = mountForm({ open: false })
		wrapper.vm.onKanalenChange(['zaken'])
		expect(wrapper.vm.kanaalNames).toEqual(['zaken'])
		wrapper.vm.onKanalenChange(null)
		expect(wrapper.vm.kanaalNames).toEqual([])
	})

	it('a form without a name or kanaal is refused before anything is sent', async () => {
		const wrapper = mountForm({ open: false })
		await wrapper.vm.save()
		expect(wrapper.vm.errors).toEqual({ name: 'Name is required', kanalen: 'At least one kanaal is required' })
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
