// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The source page shows an SFTP source's presented host key and pins it once
 * the administrator confirms they checked it with the partner (sources-sftp-
 * adapter Task 4, REQ-SFTP-001). These tests mount the real panel with the
 * section context CnDetailPage provides and assert the requests it sends and
 * what it shows.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SourceHostKeyPanel from '@/components/SourceHostKeyPanel.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{ disabled: this.disabled, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		}),
		NcCheckboxRadioSwitch: defineComponent({
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue'],
			emits: ['update:modelValue'],
			render() {
				return h('label', this.$slots.default?.())
			},
		}),
	}
})

const URL = '/index.php/apps/integriq/api/sources/src-1/host-key'
const PRESENTED = 'SHA256:presentedKey'

/**
 * Mount the panel over one loaded source.
 *
 * @param {object} object the source object
 * @return {object} the wrapper
 */
function mountFor(object) {
	return mount(SourceHostKeyPanel, {
		global: {
			provide: { cnSectionContext: { value: { objectId: 'src-1', object } } },
		},
	})
}

/**
 * The button with a test id.
 *
 * @param {object} wrapper the wrapper
 * @param {string} id the data-testid suffix
 * @return {object} the button
 */
function button(wrapper, id) {
	return wrapper.find(`[data-testid="source-host-key-${id}"]`)
}

describe('the host key panel on the source page', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
	})

	it('renders nothing for a source that is not SFTP', () => {
		const wrapper = mountFor({ type: 'ftps' })
		expect(wrapper.find('[data-testid="source-host-key-panel"]').exists()).toBe(false)
	})

	it('shows the presented key of a new source and pins it only after the confirmation', async () => {
		get.mockResolvedValue({ data: { pinned: null, presented: PRESENTED, matches: false } })
		post.mockResolvedValue({ data: { pinned: PRESENTED } })
		const wrapper = mountFor({ type: 'sftp', configuration: {} })
		expect(wrapper.find('[data-testid="source-host-key-unpinned"]').exists()).toBe(true)

		await button(wrapper, 'check').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.find('[data-testid="source-host-key-presented"]').text()).toContain(PRESENTED)
		expect(button(wrapper, 'pin').attributes('disabled')).toBeDefined()

		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(button(wrapper, 'pin').attributes('disabled')).toBeUndefined()

		await button(wrapper, 'pin').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(URL, { fingerprint: PRESENTED })
		expect(wrapper.find('[data-testid="source-host-key-pinned"]').text()).toContain(PRESENTED)
		expect(wrapper.find('[data-testid="source-host-key-matches"]').exists()).toBe(true)
	})

	it('says when the server presents the pinned key, and offers no pin', async () => {
		get.mockResolvedValue({ data: { pinned: PRESENTED, presented: PRESENTED, matches: true } })
		const wrapper = mountFor({ type: 'sftp', configuration: { hostKeyFingerprint: PRESENTED } })

		await button(wrapper, 'check').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="source-host-key-matches"]').exists()).toBe(true)
		expect(button(wrapper, 'pin').exists()).toBe(false)
	})

	it('warns when the server presents another key than the pinned one', async () => {
		get.mockResolvedValue({ data: { pinned: 'SHA256:old', presented: PRESENTED, matches: false } })
		const wrapper = mountFor({ type: 'sftp', configuration: { hostKeyFingerprint: 'SHA256:old' } })

		await button(wrapper, 'check').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('This is not the pinned key')
		expect(wrapper.find('[data-testid="source-host-key-pinned"]').text()).toContain('SHA256:old')
	})

	it('shows the server\'s refusal when pinning fails', async () => {
		get.mockResolvedValue({ data: { pinned: null, presented: PRESENTED, matches: false } })
		post.mockRejectedValue({ response: { data: { error: 'The server now presents SHA256:other, not the fingerprint you confirmed.' } } })
		const wrapper = mountFor({ type: 'sftp' })

		await button(wrapper, 'check').trigger('click')
		await flushPromises()
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()
		await button(wrapper, 'pin').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The server now presents SHA256:other, not the fingerprint you confirmed.')
		expect(wrapper.find('[data-testid="source-host-key-unpinned"]').exists()).toBe(true)
	})

	it('shows why the key could not be read', async () => {
		get.mockRejectedValue({ response: { data: { error: 'The host sftp.example.nl is not allowed.' } } })
		const wrapper = mountFor({ type: 'sftp' })

		await button(wrapper, 'check').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The host sftp.example.nl is not allowed.')
		expect(wrapper.find('[data-testid="source-host-key-presented"]').exists()).toBe(false)
	})

	it('is mounted on SourceDetail and registered under that name', () => {
		const root = join(__dirname, '..', '..')
		const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))
		const merged = buildManifest(read('src/manifest.json'), [], read('src/menu-layout.json'))
		const page = merged.pages.find((candidate) => candidate.id === 'SourceDetail')

		expect(page.config.bodyWidgets.find((entry) => entry.component === 'SourceHostKeyPanel')).toBeTruthy()
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')
		expect(registry).toContain("const SourceHostKeyPanel = lazyComponent(() => import('./components/SourceHostKeyPanel.vue'), 'SourceHostKeyPanel')")
		expect(registry).toMatch(/^\tSourceHostKeyPanel,$/m)
	})
})
