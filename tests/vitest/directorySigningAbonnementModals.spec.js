// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DirectoryRunModal, SubscriptionSigningModal and NotificatiesAbonnementForm:
 * the request each sends, how each answer and failure is shown, and what
 * opening resets.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { http, toasts } = vi.hoisted(() => ({
	http: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
	toasts: [],
}))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'disabled', 'error', 'options', 'loading'],
			emits: ['click', 'update:modelValue', 'close'],
			render() {
				return h('div', { class: name, 'data-disabled': this.disabled ? 'yes' : undefined }, [
					this.$slots.default?.(),
				])
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) => (typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined),
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})

const DirectoryRunModal = (await import('@/modals/Directory/DirectoryRunModal.vue')).default
const SubscriptionSigningModal = (await import('@/modals/Subscription/SubscriptionSigningModal.vue')).default
const NotificatiesAbonnementForm = (await import('@/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue')).default

const STUBS = { DirectoryRunSummary: { name: 'DirectoryRunSummary', props: ['record'], template: '<div class="summary" />' } }

/**
 * Mount closed, then open, so the open watcher runs as in the app.
 *
 * @param {object} component The modal.
 * @param {object} props     Its props.
 * @return {Promise<object>} The wrapper.
 */
async function opened(component, props) {
	const wrapper = mount(component, { props: { ...props, open: false }, global: { stubs: STUBS } })
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	for (const fn of Object.values(http)) {
		fn.mockReset()
	}
	toasts.length = 0
})

describe('DirectoryRunModal', () => {
	it('starts a real run on open and shows its record', async () => {
		http.post.mockResolvedValueOnce({ data: { added: 2 } })
		const wrapper = await opened(DirectoryRunModal, { source: { id: 'd1', name: 'AD' } })

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/directory/connections/d1/run', { dryRun: false, confirmRemovals: false })
		expect(wrapper.findComponent({ name: 'DirectoryRunSummary' }).props('record')).toEqual({ added: 2 })
		expect(wrapper.find('[data-testid="directory-run-confirm"]').exists()).toBe(false)
		expect(wrapper.vm.subjectName).toBe('AD')
	})

	it('runs dry in preview mode, then confirms removals when the guard holds', async () => {
		http.post.mockResolvedValueOnce({ data: { guard: { removals: 40 } } })
		http.post.mockResolvedValueOnce({ data: { removed: 40 } })
		const wrapper = await opened(DirectoryRunModal, { source: { uuid: 'u1' }, mode: 'preview' })
		expect(http.post.mock.calls[0][1]).toEqual({ dryRun: true, confirmRemovals: false })
		expect(wrapper.vm.subjectName).toBe('')

		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Confirm removals').vm.$emit('click')
		await flushPromises()

		expect(http.post.mock.calls[1]).toEqual(['/index.php/apps/integriq/api/directory/connections/u1/run', { dryRun: false, confirmRemovals: true }])
		expect(wrapper.vm.record).toEqual({ removed: 40 })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('says when the run could not start, and sends nothing without an id', async () => {
		http.post.mockRejectedValueOnce(new Error('500'))
		const wrapper = await opened(DirectoryRunModal, { source: { id: 'd1' } })
		expect(wrapper.find('[data-testid="directory-run-error"]').text()).toBe('The run could not be started.')

		const none = await opened(DirectoryRunModal, { source: {} })
		expect(http.post).toHaveBeenCalledTimes(1)
		expect(none.vm.busy).toBe(false)
		expect(none.vm.record).toBeNull()
	})

	it('closes from the close button', async () => {
		http.post.mockResolvedValueOnce({ data: {} })
		const wrapper = await opened(DirectoryRunModal, { source: { id: 'd1' } })
		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Close').vm.$emit('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('SubscriptionSigningModal', () => {
	it('generates a secret, reveals it once, and tells the list', async () => {
		http.post.mockResolvedValueOnce({ data: { signingSecret: 's3cret' } })
		const wrapper = await opened(SubscriptionSigningModal, { subscription: { uuid: 'w1', signingPosture: 'unsigned' } })
		expect(wrapper.vm.hasSecret).toBe(false)

		await wrapper.vm.generate()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/events/subscriptions/w1/signing-secret')
		expect(wrapper.vm.revealed).toBe('s3cret')
		expect(wrapper.vm.hasSecret).toBe(true)
		expect(wrapper.emitted('changed')).toHaveLength(1)
		expect(toasts).toEqual([['success', 'Signing secret generated']])
	})

	it('rotates at its own path and reveals nothing when no secret comes back', async () => {
		http.post.mockResolvedValueOnce({ data: {} })
		const wrapper = await opened(SubscriptionSigningModal, { subscription: { id: 7, protocolSettings: { signingSecret: 'x' } } })
		expect(wrapper.vm.hasSecret).toBe(true)

		await wrapper.vm.rotate()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/events/subscriptions/7/signing-secret/rotate')
		expect(wrapper.vm.revealed).toBe('')
		expect(toasts).toEqual([['success', 'Signing secret rotated']])
	})

	it('reports a failed operation without marking the webhook signed', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		const wrapper = await opened(SubscriptionSigningModal, { subscription: { uuid: 'w1' } })

		await wrapper.vm.generate()

		expect(toasts).toEqual([['error', 'Signing operation failed: Forbidden']])
		expect(wrapper.vm.hasSecret).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.emitted('changed')).toBeUndefined()

		http.post.mockRejectedValueOnce({})
		await wrapper.vm.rotate()
		expect(toasts.at(-1)).toEqual(['error', 'Signing operation failed'])
	})

	it('sends nothing without an id, and reopening hides the revealed secret', async () => {
		const none = await opened(SubscriptionSigningModal, { subscription: null })
		await none.vm.generate()
		expect(http.post).not.toHaveBeenCalled()

		http.post.mockResolvedValueOnce({ data: { signingSecret: 'abc' } })
		const wrapper = await opened(SubscriptionSigningModal, { subscription: { uuid: 'w1' } })
		await wrapper.vm.generate()
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.revealed).toBe('')
	})

	it('copies only a revealed secret', async () => {
		const writeText = vi.fn()
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await opened(SubscriptionSigningModal, { subscription: { uuid: 'w1' } })

		wrapper.vm.copy()
		expect(writeText).not.toHaveBeenCalled()

		wrapper.vm.revealed = 'abc'
		wrapper.vm.copy()
		expect(writeText).toHaveBeenCalledWith('abc')
		expect(toasts).toEqual([['success', 'Copied to clipboard']])
	})

	it('explains a webhook saved before signing was recorded', async () => {
		const wrapper = await opened(SubscriptionSigningModal, { subscription: { uuid: 'w1' } })
		expect(wrapper.vm.statusText).toBe('This webhook was saved before signing was recorded. Save it again to see whether it signs.')
		const unsigned = await opened(SubscriptionSigningModal, { subscription: { uuid: 'w1', signingPosture: 'unsigned' } })
		expect(unsigned.vm.statusText).toBe('This webhook delivers unsigned. Nobody recorded why.')
	})
})

describe('NotificatiesAbonnementForm', () => {
	const SOURCES = { data: { results: [{ id: 1, name: 'ZRC' }, { uuid: 'u2' }] } }

	it('loads the sources once and seeds an empty model for a new abonnement', async () => {
		http.get.mockResolvedValue(SOURCES)
		const wrapper = await opened(NotificatiesAbonnementForm, { abonnement: null })

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/source', { params: { _limit: 500 } })
		expect(wrapper.vm.sourceOptions).toEqual([{ id: '1', label: 'ZRC' }, { id: 'u2', label: undefined }])
		expect(wrapper.vm.model).toEqual({ name: '', sourceId: '', authHeaderName: 'Authorization', authScheme: '' })
		expect(wrapper.vm.isEdit).toBe(false)

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('seeds from an existing abonnement and shows an unknown source by its id', async () => {
		http.get.mockResolvedValue({ data: [] })
		const wrapper = await opened(NotificatiesAbonnementForm, {
			abonnement: { id: 'a1', name: 'Zaken', sourceId: 'gone', authScheme: 'Bearer', kanalen: [{ naam: 'zaken' }, { naam: '' }, {}] },
		})

		expect(wrapper.vm.isEdit).toBe(true)
		expect(wrapper.vm.model).toEqual({ name: 'Zaken', sourceId: 'gone', authHeaderName: 'Authorization', authScheme: 'Bearer' })
		expect(wrapper.vm.kanaalNames).toEqual(['zaken'])
		expect(wrapper.vm.selectedSource).toEqual({ id: 'gone', label: 'gone' })
	})

	it('refuses a save without a name or a kanaal', async () => {
		http.get.mockResolvedValue({ data: [] })
		const wrapper = await opened(NotificatiesAbonnementForm, { abonnement: null })

		await wrapper.vm.save()

		expect(wrapper.vm.errors).toEqual({ name: 'Name is required', kanalen: 'At least one kanaal is required' })
		expect(http.post).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('creates a new abonnement from the picked source and kanalen', async () => {
		http.get.mockResolvedValue(SOURCES)
		http.post.mockResolvedValueOnce({ data: {} })
		const wrapper = await opened(NotificatiesAbonnementForm, { abonnement: null })
		wrapper.vm.model.name = 'Zaken'
		wrapper.vm.model.authHeaderName = ''
		wrapper.vm.onSourcePick({ id: '1' })
		expect(wrapper.vm.selectedSource).toEqual({ id: '1', label: 'ZRC' })
		wrapper.vm.onKanalenChange(['zaken', 'documenten'])

		await wrapper.vm.save()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/notificaties/abonnementen', {
			name: 'Zaken',
			sourceId: '1',
			kanalen: [{ naam: 'zaken', filters: {} }, { naam: 'documenten', filters: {} }],
			authHeaderName: 'Authorization',
			authScheme: '',
		})
		expect(toasts).toEqual([['success', 'Abonnement created']])
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('updates an existing abonnement and reports a failed save', async () => {
		http.get.mockResolvedValue({ data: [] })
		const wrapper = await opened(NotificatiesAbonnementForm, { abonnement: { uuid: 'a2', name: 'X', kanalen: [{ naam: 'zaken' }] } })

		http.put.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.save()
		expect(http.put.mock.calls[0][0]).toBe('/index.php/apps/integriq/api/notificaties/abonnementen/a2')
		expect(toasts).toEqual([['success', 'Abonnement updated']])

		http.put.mockRejectedValueOnce({ message: 'timeout' })
		await wrapper.vm.save()
		expect(toasts.at(-1)).toEqual(['error', 'Failed to save abonnement: timeout'])
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('clears the source and kanalen on odd input, and survives a failed source load', async () => {
		http.get.mockRejectedValueOnce(new Error('down'))
		const wrapper = await opened(NotificatiesAbonnementForm, { abonnement: null })
		expect(wrapper.vm.sourceOptions).toEqual([])
		expect(wrapper.vm.sourcesLoading).toBe(false)

		wrapper.vm.onSourcePick(null)
		expect(wrapper.vm.model.sourceId).toBe('')
		expect(wrapper.vm.selectedSource).toBeNull()
		wrapper.vm.onKanalenChange('zaken')
		expect(wrapper.vm.kanaalNames).toEqual([])
	})
})
