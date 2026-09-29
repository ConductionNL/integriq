// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The change set on the approval screen (connectors-inavigator-case-types
 * task 5, REQ-INAV-003 and REQ-INAV-004).
 *
 * A gated synchronization stores what it would create, change and remove on
 * its approval_request. These tests mount the real detail page and assert
 * the approver sees those objects and their field diffs before accepting,
 * and that an accept whose source changed after the preview lands on the new
 * request instead of reporting success.
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApprovalDetail from '@/views/Approvals/ApprovalDetail.vue'

const { get, post, push, showError, showSuccess, showWarning } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	push: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess, showWarning }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click'],
			render() {
				return h(
					'div',
					{ class: name, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcEmptyContent: stub('NcEmptyContent', ['name', 'description']),
		NcLoadingIcon: stub('NcLoadingIcon', ['size']),
	}
})

const CHANGE_SET = {
	created: [
		{ originId: 'zt-parkeren', fields: { omschrijving: 'Parkeervergunning' } },
		{
			originId: 'zt-standplaats',
			fields: { omschrijving: 'Standplaatsvergunning' },
		},
	],
	changed: [
		{
			originId: 'zt-evenement',
			targetId: 'target-2',
			fields: [
				{
					field: 'omschrijving',
					before: 'Evenement',
					after: 'Evenementenvergunning',
				},
				{
					field: 'statustypen',
					before: ['Ontvangen'],
					after: ['Ontvangen', 'Besloten'],
				},
			],
		},
	],
	removed: [{ originId: 'zt-oud', targetId: 'target-9' }],
	unchanged: 12,
	counts: { created: 2, changed: 1, removed: 1, unchanged: 12 },
	truncated: false,
	limit: 500,
	fingerprint: 'abc',
}

const REQUEST = {
	id: 'approval-1',
	status: 'pending',
	synchronizationId: 'sync-1',
	approverGroup: 'admin',
	changeSet: CHANGE_SET,
}

/**
 * Mount the real detail page on a request.
 *
 * @param {object} request The approval request the API answers.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDetail(request = REQUEST) {
	get.mockResolvedValue({ data: request })
	const wrapper = mount(ApprovalDetail, {
		global: {
			mocks: { $route: { params: { id: 'approval-1' } }, $router: { push } },
		},
	})
	await flushPromises()
	return wrapper
}

describe('the change set on the approval screen', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		push.mockReset()
		showError.mockReset()
		showWarning.mockReset()
	})

	it('shows the counts of what an accept would write', async () => {
		const wrapper = await mountDetail()
		const summary = wrapper.find('[data-testid="change-set-counts"]').text()

		expect(summary).toContain('2 to create')
		expect(summary).toContain('1 to change')
		expect(summary).toContain('1 to remove')
		expect(summary).toContain('12 unchanged')
	})

	it('lists the created objects first', async () => {
		const wrapper = await mountDetail()
		const panel = wrapper.find('[data-testid="change-set-panel"]').text()

		expect(panel).toContain('zt-parkeren')
		expect(panel).toContain('zt-standplaats')
		expect(panel).not.toContain('zt-evenement')
	})

	it('shows each changed field before and after', async () => {
		const wrapper = await mountDetail()
		await wrapper.find('[data-testid="change-set-tab-changed"]').trigger('click')
		const panel = wrapper.find('[data-testid="change-set-panel"]').text()

		expect(panel).toContain('zt-evenement')
		expect(panel).toContain('omschrijving')
		expect(panel).toContain('Evenement')
		expect(panel).toContain('Evenementenvergunning')
		expect(panel).toContain('Besloten')
	})

	it('lists what would be removed', async () => {
		const wrapper = await mountDetail()
		await wrapper.find('[data-testid="change-set-tab-removed"]').trigger('click')

		expect(wrapper.find('[data-testid="change-set-panel"]').text()).toContain(
			'zt-oud',
		)
	})

	it('marks the selected tab for assistive technology', async () => {
		const wrapper = await mountDetail()
		const changed = wrapper.find('[data-testid="change-set-tab-changed"]')
		await changed.trigger('click')

		expect(changed.attributes('aria-selected')).toBe('true')
		expect(
			wrapper
				.find('[data-testid="change-set-tab-created"]')
				.attributes('aria-selected'),
		).toBe('false')
	})

	it('says when the list was cut, with the exact counts kept', async () => {
		const wrapper = await mountDetail({
			...REQUEST,
			changeSet: {
				...CHANGE_SET,
				truncated: true,
				counts: { ...CHANGE_SET.counts, created: 740 },
			},
		})

		expect(
			wrapper.find('[data-testid="change-set-truncated"]').text(),
		).toContain('500')
		expect(wrapper.find('[data-testid="change-set-counts"]').text()).toContain(
			'740 to create',
		)
	})

	it('shows no change set on a request that has none', async () => {
		const wrapper = await mountDetail({ ...REQUEST, changeSet: null })

		expect(wrapper.find('[data-testid="change-set"]').exists()).toBe(false)
	})

	it('opens the new request when the source changed after the preview', async () => {
		const wrapper = await mountDetail()
		post.mockRejectedValue({
			response: {
				status: 409,
				data: {
					message: 'approval_superseded',
					_approval: {
						resumeResult: 'superseded',
						supersededBy: 'approval-2',
					},
				},
			},
		})

		await wrapper
			.findAll('.NcButton')
			.find((button) => button.text() === 'Approve')
			.trigger('click')
		await flushPromises()

		expect(showWarning).toHaveBeenCalledWith(
			'The source changed after this preview. Nothing was written. A new request shows the new changes.',
		)
		expect(showError).not.toHaveBeenCalled()
		expect(push).toHaveBeenCalledWith('/approvals/approval-2')
	})
})
