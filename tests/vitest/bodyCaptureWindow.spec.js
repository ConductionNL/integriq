// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The investigation window on the source page, its dialog, and the call log
 * detail that says when no body was stored
 * (outbound-call-log-investigation-window-page-and-allowlist, Task 6, REQ-OCD-011).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { http, toasts } = vi.hoisted(() => ({ http: { post: vi.fn(), delete: vi.fn() }, toasts: [] }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDetailGrid: defineComponent({
			name: 'CnDetailGrid',
			props: ['items', 'layout'],
			render() {
				return h('dl', { class: 'grid' }, this.items.map((item) => h('div', `${item.label}=${item.value}`)))
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['disabled', 'variant'],
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcDialog: defineComponent({
			name: 'NcDialog',
			props: ['open', 'name', 'size'],
			emits: ['update:open'],
			render() {
				return h('div', { class: 'dialog' }, [h('h2', this.name), this.$slots.default?.(), this.$slots.actions?.()])
			},
		}),
		NcNoteCard: defineComponent({
			name: 'NcNoteCard',
			props: ['type'],
			render() {
				return h('p', { class: `note note--${this.type}` }, this.$slots.default?.())
			},
		}),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label', 'type', 'min'],
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					value: this.modelValue,
					'aria-label': this.label,
					onInput: (event) => this.$emit('update:modelValue', event.target.value),
				})
			},
		}),
	}
})

const BodyCaptureWindow = (await import('@/components/BodyCaptureWindow.vue')).default
const BodyCaptureWindowDialog = (await import('@/dialogs/BodyCaptureWindowDialog.vue')).default
const CallLogDetail = (await import('@/components/callLog/CallLogDetail.vue')).default

const NOW = new Date('2026-11-14T12:00:00Z')

/**
 * Mount the window inside a section context.
 *
 * @param {object} object The loaded source.
 * @return {object} The wrapper.
 */
function windowFor(object) {
	return mount(BodyCaptureWindow, { global: { provide: { cnSectionContext: { value: { objectId: 's1', object } } } } })
}

describe('BodyCaptureWindow', () => {
	beforeEach(() => {
		http.post.mockReset()
		http.delete.mockReset()
		toasts.length = 0
		vi.useFakeTimers()
		vi.setSystemTime(NOW)
		window.OC = { isUserAdmin: () => true }
	})

	afterEach(() => {
		vi.useRealTimers()
		delete window.OC
	})

	it('says no window is open and offers an administrator to open one', () => {
		const wrapper = windowFor({})
		expect(wrapper.find('[data-testid="body-capture-state"]').text()).toBe('No investigation window is open. Call bodies are not stored.')
		expect(wrapper.find('[data-testid="body-capture-open"]').text()).toBe('Open investigation window')
		expect(wrapper.find('[data-testid="body-capture-stop"]').exists()).toBe(false)
	})

	it('reads a window that already ended as closed', () => {
		const wrapper = windowFor({ bodyCaptureUntil: '2026-11-14T11:00:00Z', bodyCaptureReason: 'old' })
		expect(wrapper.find('[data-testid="body-capture-open"]').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('old')
	})

	it('shows until when bodies are stored, the reason and a stop button while open', () => {
		const until = '2026-11-15T12:00:00Z'
		const wrapper = windowFor({ bodyCaptureUntil: until, bodyCaptureReason: 'melding 4711' })
		expect(wrapper.find('[data-testid="body-capture-state"]').text()).toBe(`Bodies are stored until ${new Date(until).toLocaleString()}`)
		expect(wrapper.text()).toContain('Reason: melding 4711')
		expect(wrapper.find('[data-testid="body-capture-stop"]').text()).toBe('Stop early')
		expect(wrapper.find('[data-testid="body-capture-open"]').exists()).toBe(false)
	})

	it('gives a user who is not an administrator no button', () => {
		window.OC = { isUserAdmin: () => false }
		expect(windowFor({}).find('[data-testid="body-capture-open"]').exists()).toBe(false)
		expect(windowFor({ bodyCaptureUntil: '2026-11-15T12:00:00Z' }).find('[data-testid="body-capture-stop"]').exists()).toBe(false)
	})

	it('opens the dialog and takes the window it opened', async () => {
		const wrapper = windowFor({})
		await wrapper.find('[data-testid="body-capture-open"]').trigger('click')
		const dialog = wrapper.findComponent(BodyCaptureWindowDialog)
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('sourceId')).toBe('s1')

		dialog.vm.$emit('opened', { bodyCaptureUntil: '2026-11-15T12:00:00Z', bodyCaptureReason: 'r' })
		await flushPromises()
		expect(wrapper.findComponent(BodyCaptureWindowDialog).exists()).toBe(false)
		expect(wrapper.find('[data-testid="body-capture-stop"]').exists()).toBe(true)
		expect(toasts).toEqual([['success', 'Investigation window opened']])
	})

	it('stops the window early through the close endpoint', async () => {
		http.delete.mockResolvedValue({ data: { bodyCaptureUntil: NOW.toISOString(), bodyCaptureReason: 'r' } })
		const wrapper = windowFor({ bodyCaptureUntil: '2026-11-15T12:00:00Z', bodyCaptureReason: 'r' })
		await wrapper.find('[data-testid="body-capture-stop"]').trigger('click')
		await flushPromises()
		expect(http.delete).toHaveBeenCalledWith('/index.php/apps/integriq/api/sources/s1/body-capture')
		expect(wrapper.find('[data-testid="body-capture-open"]').exists()).toBe(true)
		expect(toasts).toEqual([['success', 'Investigation window closed']])
	})

	it('keeps the window and names the refusal when stopping fails', async () => {
		http.delete.mockRejectedValue({ response: { data: { error: 'This source has no open investigation window.' } } })
		const wrapper = windowFor({ bodyCaptureUntil: '2026-11-15T12:00:00Z' })
		await wrapper.find('[data-testid="body-capture-stop"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="body-capture-stop"]').exists()).toBe(true)
		expect(toasts).toEqual([['error', 'The investigation window could not be closed. This source has no open investigation window.']])
	})
})

describe('BodyCaptureWindowDialog', () => {
	beforeEach(() => {
		http.post.mockReset()
	})

	/**
	 * Fill the hours and reason fields.
	 *
	 * @param {object} wrapper The dialog wrapper.
	 * @param {string} hours The hours.
	 * @param {string} reason The reason.
	 */
	async function fill(wrapper, hours, reason) {
		await wrapper.find('[data-testid="body-capture-hours"]').setValue(hours)
		await wrapper.find('[data-testid="body-capture-reason"]').setValue(reason)
	}

	it('refuses to submit without a reason', async () => {
		const wrapper = mount(BodyCaptureWindowDialog, { props: { open: true, sourceId: 's1' } })
		await fill(wrapper, '24', '   ')
		expect(wrapper.find('[data-testid="body-capture-submit"]').attributes('disabled')).toBeDefined()
	})

	it('opens the window with the hours and the trimmed reason, and hands on the answer', async () => {
		http.post.mockResolvedValue({ data: { bodyCaptureUntil: '2026-11-15T12:00:00Z' } })
		const wrapper = mount(BodyCaptureWindowDialog, { props: { open: true, sourceId: 's1' } })
		await fill(wrapper, '24', ' melding 4711: verkeerde zaaktypen ')
		await wrapper.find('[data-testid="body-capture-submit"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/sources/s1/body-capture',
			{ hours: 24, reason: 'melding 4711: verkeerde zaaktypen' },
		)
		expect(wrapper.emitted('opened')).toEqual([[{ bodyCaptureUntil: '2026-11-15T12:00:00Z' }]])
	})

	it('shows the server refusal and stays open', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'Choose a number of hours from 1 to 72.' } } })
		const wrapper = mount(BodyCaptureWindowDialog, { props: { open: true, sourceId: 's1' } })
		await fill(wrapper, '500', 'r')
		await wrapper.find('[data-testid="body-capture-submit"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="body-capture-error"]').text()).toBe('Choose a number of hours from 1 to 72.')
		expect(wrapper.emitted('opened')).toBeUndefined()
	})

	it('closes on cancel', async () => {
		const wrapper = mount(BodyCaptureWindowDialog, { props: { open: true, sourceId: 's1' } })
		await wrapper.findAll('button')[0].trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('CallLogDetail', () => {
	it('says why a record made outside a window has no body', () => {
		const wrapper = mount(CallLogDetail, { props: { row: { statusCode: 200, bodyCaptured: false } } })
		expect(wrapper.find('[data-testid="call-log-no-body"]').text()).toBe('No body stored: no investigation window was open')
	})

	it('says it too when the record carries no capture flag at all', () => {
		const wrapper = mount(CallLogDetail, { props: { row: { statusCode: 200 } } })
		expect(wrapper.find('[data-testid="call-log-no-body"]').exists()).toBe(true)
	})

	it('shows a captured record without the notice, its fields and its bags', () => {
		const row = {
			'@self': { id: 'x' },
			statusCode: 200,
			bodyCaptured: true,
			request: { method: 'GET', url: 'https://zaken.example/zaken' },
			response: { body: { results: [1] } },
		}
		const wrapper = mount(CallLogDetail, { props: { row } })
		expect(wrapper.find('[data-testid="call-log-no-body"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Status code=200')
		expect(wrapper.text()).toContain('Method=GET')
		expect(wrapper.find('pre').text()).toBe(JSON.stringify(row.response, null, 2))
		expect(wrapper.text()).not.toContain('@self')
	})
})
