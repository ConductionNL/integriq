// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Beheer > Service fetches (forms-service-fetch-for-apps Task 5, REQ-SF-001):
 * "Testen" posts sample inputs for one fetch to the admin-only test route and
 * shows the source's raw answer next to the mapped outputs; the row action
 * opens the modal on the shared bus, and the manifest lists the page.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TestServiceFetchModal from '@/modals/v2/TestServiceFetchModal.vue'
import { testServiceFetchHandler } from '@/handlers/actionHandlers.js'
import { EVENT_OPEN_TEST_SERVICE_FETCH, modalBus } from '@/handlers/modalBus.js'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const passthrough = (name) => defineComponent({
		name,
		render() {
			return h('div', { 'data-component': name }, this.$slots.default?.())
		},
	})
	return {
		NcModal: passthrough('NcModal'),
		NcNoteCard: passthrough('NcNoteCard'),
		NcLoadingIcon: passthrough('NcLoadingIcon'),
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('input', { 'aria-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
			},
		}),
	}
})

const FETCH = {
	slug: 'kadaster-eigenaar',
	title: 'Eigenaar volgens het Kadaster',
	inputs: [{ name: 'bsn', required: true }, { name: 'nummeraanduiding' }, { type: 'nameless' }],
}

/**
 * Mount the modal open over the Kadaster fetch.
 *
 * @param {object} [fetch] The fetch row.
 * @return {object} The wrapper.
 */
function mountIt(fetch = FETCH) {
	return mount(TestServiceFetchModal, { props: { open: true, fetch } })
}

beforeEach(() => {
	post.mockReset()
})

describe('TestServiceFetchModal', () => {
	it('offers one field per named input, marking the required ones', () => {
		const labels = mountIt().findAll('input').map((input) => input.attributes('aria-label'))
		expect(labels).toEqual(['bsn (required)', 'nummeraanduiding'])
	})

	it('posts the filled-in inputs to the test route and shows the raw answer next to the outputs', async () => {
		post.mockResolvedValue({ data: { raw: { status: 200, body: { eigenaar: true } }, outputs: { isEigenaar: true }, refusal: null, detail: null } })
		const w = mountIt()

		await w.findAll('input')[0].setValue('999993653')
		await w.get('[data-testid="test-service-fetch-run"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/service-fetches/kadaster-eigenaar/test', { inputs: { bsn: '999993653' } })
		expect(JSON.parse(w.get('[data-testid="test-service-fetch-raw"]').text())).toEqual({ status: 200, body: { eigenaar: true } })
		expect(JSON.parse(w.get('[data-testid="test-service-fetch-outputs"]').text())).toEqual({ isEigenaar: true })
		expect(w.find('[data-testid="test-service-fetch-refusal"]').exists()).toBe(false)
	})

	it('names a refusal and still shows the raw answer the source gave', async () => {
		post.mockResolvedValue({ data: { raw: { status: 200, body: {} }, outputs: null, refusal: 'mapping_failed', detail: 'isEigenaar' } })
		const w = mountIt()

		await w.get('[data-testid="test-service-fetch-run"]').trigger('click')
		await flushPromises()

		expect(w.get('[data-testid="test-service-fetch-refusal"]').text()).toBe('Refused: mapping_failed (isEigenaar)')
		expect(w.find('[data-testid="test-service-fetch-raw"]').exists()).toBe(true)
		expect(w.text()).toContain('No outputs.')
	})

	it('says why when the test route refuses, and falls back to a plain message', async () => {
		post.mockRejectedValueOnce({ response: { data: { error: 'Service fetch not found' } } })
		const w = mountIt()
		await w.get('[data-testid="test-service-fetch-run"]').trigger('click')
		await flushPromises()
		expect(w.get('[data-testid="test-service-fetch-error"]').text()).toBe('Service fetch not found')

		post.mockRejectedValueOnce(new Error('network'))
		await w.get('[data-testid="test-service-fetch-run"]').trigger('click')
		await flushPromises()
		expect(w.get('[data-testid="test-service-fetch-error"]').text()).toBe('The service fetch could not be tested.')
	})

	it('starts empty for another fetch, and does not run without a slug', async () => {
		post.mockResolvedValue({ data: { raw: null, outputs: null, refusal: 'invalid_input', detail: 'bsn' } })
		const w = mountIt()
		await w.findAll('input')[0].setValue('1')
		await w.get('[data-testid="test-service-fetch-run"]').trigger('click')
		await flushPromises()

		await w.setProps({ fetch: { slug: 'rdw', inputs: [] } })
		expect(w.find('[data-testid="test-service-fetch-refusal"]').exists()).toBe(false)
		expect(w.text()).toContain('This fetch declares no inputs.')

		post.mockClear()
		await w.setProps({ fetch: null })
		await w.vm.runTest()
		expect(post).not.toHaveBeenCalled()
	})
})

describe('the Service fetches page', () => {
	it('opens the modal for the clicked row through the shared bus', () => {
		const seen = vi.fn()
		modalBus.on(EVENT_OPEN_TEST_SERVICE_FETCH, seen)
		testServiceFetchHandler({ actionId: 'test', item: FETCH })
		modalBus.off(EVENT_OPEN_TEST_SERVICE_FETCH, seen)
		expect(seen).toHaveBeenCalledWith({ fetch: FETCH })
	})

	it('is an index page over serviceFetch with the test row action, in the Connections menu', () => {
		const manifest = JSON.parse(readFileSync(join(__dirname, '..', '..', 'src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((entry) => entry.id === 'ServiceFetches')
		expect(page).toMatchObject({ type: 'index', route: '/service-fetches', config: { register: 'integriq', schema: 'serviceFetch' } })
		expect(page.config.actions).toContainEqual(expect.objectContaining({ id: 'test', handler: 'testServiceFetchHandler' }))
		const connections = manifest.menu.find((entry) => entry.id === 'ConnectionsGroup')
		expect(connections.children.map((child) => child.route)).toContain('ServiceFetches')
	})
})
