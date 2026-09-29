// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Webhook signing modal states what a receiver must compute
 * (signed-outbound-webhooks, REQ-SOW-002), and says whether a webhook is
 * signed from the readable `signingPosture`, because `protocolSettings` is
 * writeOnly and never reaches the page (REQ-SOW-003).
 *
 * @spec openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-the-subscription-page-states-what-a-receiver-must-compute-req-sow-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SubscriptionSigningModal from '@/modals/Subscription/SubscriptionSigningModal.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'close'],
			render() {
				return h(
					'div',
					{ class: name, onClick: () => this.$emit('click') },
					[this.$slots.default?.()],
				)
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcModal: stub('NcModal', ['labelId']),
		NcNoteCard: stub('NcNoteCard', ['type']),
	}
})

async function openFor(subscription) {
	const wrapper = mount(SubscriptionSigningModal, {
		props: { open: false, subscription },
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('SubscriptionSigningModal', () => {
	it('states the recipe: header, value shape, algorithm, raw body, tolerance, rotation', async () => {
		const wrapper = await openFor({ uuid: 's1', signingPosture: 'signed' })
		const recipe = wrapper.find('[data-testid="signing-recipe"]').text()
		expect(recipe).toContain('X-OpenConnector-Signature')
		expect(recipe).toContain('t=<unix-ts>,v1=<hex>')
		expect(recipe).toContain('HMAC-SHA256')
		expect(recipe).toContain('<t>.<rawBody>')
		expect(recipe).toMatch(/exactly as (it was )?received/)
		expect(recipe).toMatch(/tolerance/)
		expect(recipe).toMatch(/two v1 values/)
	})

	it('shows the recipe while the secret itself stays hidden', async () => {
		const wrapper = await openFor({ uuid: 's1', signingPosture: 'signed' })
		expect(wrapper.find('[data-testid="signing-recipe"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="signing-reveal"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('whsec_')
	})

	it('reads a signed webhook from signingPosture, since protocolSettings never arrives', async () => {
		const wrapper = await openFor({ uuid: 's1', signingPosture: 'signed' })
		expect(wrapper.find('[data-testid="signing-status"]').text()).toContain(
			'is signed',
		)
		expect(wrapper.text()).toContain('Rotate secret')
	})

	it('marks an unsigned webhook and gives the reason somebody wrote', async () => {
		const wrapper = await openFor({
			uuid: 's2',
			signingPosture: 'unsigned',
			unsignedReason: 'receiver cannot verify HMAC yet',
		})
		const status = wrapper.find('[data-testid="signing-status"]').text()
		expect(status).toContain('unsigned')
		expect(status).toContain('receiver cannot verify HMAC yet')
		expect(wrapper.text()).not.toContain('Rotate secret')
	})
})
