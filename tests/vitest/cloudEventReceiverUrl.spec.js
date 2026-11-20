// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CloudEvents receiver URL on the source and endpoint pages
 * (cloudevents-receiver task 1.4, REQ-017): a source page shows that source's
 * exact receiver URL with a copy action, the endpoint page shows the pattern,
 * and the manifest places the widget on both pages.
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import CloudEventReceiverUrl from '@/components/CloudEventReceiverUrl.vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'ariaLabel'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{ 'aria-label': this.ariaLabel, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		}),
	}
})

const root = join(__dirname, '..', '..')
const manifest = JSON.parse(readFileSync(join(root, 'src/manifest.json'), 'utf8'))
const origin = window.location.origin

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('CloudEventReceiverUrl', () => {
	it('shows the exact receiver URL of the source, lower-cased as the route matches it', () => {
		const w = mount(CloudEventReceiverUrl, { props: { sourceId: 'AB12-cd34' } })

		expect(w.get('[data-testid="cloudevent-receiver-url-value"]').text())
			.toBe(`${origin}/index.php/apps/integriq/api/cloudevents/sources/ab12-cd34`)
		expect(w.find('button').exists()).toBe(true)
	})

	it('copies the exact URL and says so', async () => {
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const w = mount(CloudEventReceiverUrl, { props: { sourceId: 'src-1' } })

		await w.get('button').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith(`${origin}/index.php/apps/integriq/api/cloudevents/sources/src-1`)
		expect(w.get('button').text()).toBe('Copied')

		await w.setProps({ sourceId: 'src-2' })
		expect(w.get('button').text()).toBe('Copy')
	})

	it('keeps saying Copy when the clipboard refuses', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw new Error('denied') }) } })
		const w = mount(CloudEventReceiverUrl, { props: { sourceId: 'src-1' } })

		await w.get('button').trigger('click')
		await flushPromises()

		expect(w.get('button').text()).toBe('Copy')
	})

	it('shows the pattern without a copy action when no source is known, or the id is not one the route accepts', () => {
		for (const sourceId of ['', 'a/b', 'x y']) {
			const w = mount(CloudEventReceiverUrl, { props: { sourceId } })
			expect(w.get('[data-testid="cloudevent-receiver-url-value"]').text())
				.toBe(`${origin}/index.php/apps/integriq/api/cloudevents/sources/{source id}`)
			expect(w.find('button').exists()).toBe(false)
			expect(w.text()).toContain('Open the source to copy its exact URL.')
		}
	})

	it('is placed on the source page with the source id, and on the endpoint page without one', () => {
		const page = (id) => manifest.pages.find((entry) => entry.id === id)
		const section = (id) => (page(id).config.bodyWidgets || []).find((entry) => entry.component === 'CloudEventReceiverUrl')

		expect(section('SourceDetail')).toMatchObject({ placement: 'after-data', props: { sourceId: '@objectId' } })
		expect(section('EndpointDetail')).toMatchObject({ placement: 'after-data' })
		expect(section('EndpointDetail').props).toBeUndefined()
	})
})
