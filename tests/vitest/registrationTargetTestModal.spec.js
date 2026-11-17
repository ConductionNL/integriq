// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RegistrationTargetTestModal: the dry run it sends, how the mapped body and
 * a refusal are shown, what a malformed hand-over or a failed request does,
 * and what opening resets.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { http } = vi.hoisted(() => ({ http: { post: vi.fn() } }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			props: ['disabled', 'type', 'variant'],
			emits: ['click', 'close'],
			render() {
				return h('div', { class: name, 'data-disabled': this.disabled ? 'yes' : undefined }, [
					this.$slots.default?.(),
				])
			},
		})
	return { NcButton: stub('NcButton'), NcLoadingIcon: stub('NcLoadingIcon'), NcModal: stub('NcModal'), NcNoteCard: stub('NcNoteCard') }
})

const RegistrationTargetTestModal = (await import('@/modals/Registration/RegistrationTargetTestModal.vue')).default

const TARGET = { slug: 'json milieu', title: 'Milieu' }

/**
 * Mount closed, then open, so the open watcher runs as in the app.
 *
 * @param {object} target The target row.
 * @return {Promise<object>} The wrapper.
 */
async function opened(target = TARGET) {
	const wrapper = mount(RegistrationTargetTestModal, { props: { open: false, target } })
	await wrapper.setProps({ open: true })
	return wrapper
}

/**
 * Press "Run test".
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<void>}
 */
async function runTest(wrapper) {
	await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Run test').vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	http.post.mockReset()
})

describe('RegistrationTargetTestModal', () => {
	it('asks the server sample with an empty hand-over and shows the mapped body', async () => {
		http.post.mockResolvedValue({ data: { ok: true, dry: true, kind: 'json', mapped: { subject: 'milieu' } } })
		const wrapper = await opened()

		await runTest(wrapper)

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/registration-targets/json%20milieu/test', { handover: {} })
		expect(wrapper.find('pre').text()).toBe(JSON.stringify({ subject: 'milieu' }, null, 2))
		expect(wrapper.text()).not.toContain('Refused')
	})

	it('sends a pasted hand-over as an object', async () => {
		http.post.mockResolvedValue({ data: { ok: true, mapped: {} } })
		const wrapper = await opened()

		await wrapper.find('textarea').setValue('{"reference":"OF-1","answers":{}}')
		await runTest(wrapper)

		expect(http.post.mock.calls[0][1]).toEqual({ handover: { reference: 'OF-1', answers: {} } })
	})

	it('shows a refusal with its code and message, and no body', async () => {
		http.post.mockResolvedValue({ data: { ok: false, dry: true, code: 'mapping-failed', message: 'no subject' } })
		const wrapper = await opened()

		await runTest(wrapper)

		expect(wrapper.text()).toContain('Refused (mapping-failed): no subject')
		expect(wrapper.find('pre').exists()).toBe(false)
	})

	it('refuses a hand-over that is not a JSON object without sending it', async () => {
		const wrapper = await opened()

		for (const input of ['not json', '[1, 2]', 'null']) {
			await wrapper.find('textarea').setValue(input)
			await runTest(wrapper)
			expect(wrapper.find('[role="alert"]').text()).toBe('The hand-over must be a JSON object.')
		}
		expect(http.post).not.toHaveBeenCalled()
	})

	it('says when the request fails', async () => {
		http.post.mockRejectedValue(new Error('network'))
		const wrapper = await opened()

		await runTest(wrapper)

		expect(wrapper.text()).toContain('The test could not be run.')
	})

	it('sends nothing for a row without a slug', async () => {
		const wrapper = await opened({ title: 'No slug' })

		await runTest(wrapper)

		expect(http.post).not.toHaveBeenCalled()
	})

	it('clears the previous answer when it opens again', async () => {
		http.post.mockResolvedValue({ data: { ok: true, mapped: { a: 1 } } })
		const wrapper = await opened()
		await wrapper.find('textarea').setValue('{"x":1}')
		await runTest(wrapper)

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.find('pre').exists()).toBe(false)
		expect(wrapper.find('textarea').element.value).toBe('')
	})

	it('closes from the close button', async () => {
		const wrapper = await opened()

		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Close').vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
