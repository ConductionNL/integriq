// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call log screen replays, dry runs and fires calls
 * (outbound-call-delivery-and-replay REQ-OCD-002 and REQ-OCD-003).
 *
 * CallLogController has answered preview, replay (single and bulk), dry run
 * and fire for a while, but nothing on the screen called it, so a failed
 * delivery could only be replayed with curl. These tests drive the real
 * components against a mocked axios and assert the exact requests they send
 * to those routes and what they show of the answer.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CallLogRowActions from '@/components/callLog/CallLogRowActions.vue'
import CallBulkReplayModal from '@/modals/CallLog/CallBulkReplayModal.vue'
import CallFireModal from '@/modals/CallLog/CallFireModal.vue'
import CallReplayModal from '@/modals/CallLog/CallReplayModal.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve. Stand-ins keep the real names and the props these
// components bind, and render their default slot so the text is asserted on.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props, tag = 'div') =>
		defineComponent({
			name,
			props,
			emits: ['click', 'close', 'update:modelValue'],
			render() {
				return h(tag, { class: name }, this.$slots.default?.())
			},
		})
	return {
		NcModal: stub('NcModal', ['labelId']),
		NcButton: stub('NcButton', ['variant', 'disabled', 'type'], 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', [
			'modelValue',
			'type',
			'name',
			'value',
		]),
		NcSelect: stub('NcSelect', [
			'inputId',
			'inputLabel',
			'ariaLabelCombobox',
			'modelValue',
			'options',
			'loading',
			'clearable',
			'placeholder',
		]),
		NcTextField: stub('NcTextField', ['label', 'modelValue']),
		NcTextArea: stub('NcTextArea', ['label', 'modelValue', 'resize']),
		NcActions: stub('NcActions', []),
		NcActionButton: stub('NcActionButton', ['closeAfterClick']),
	}
})

const CALL = 'a1b2c3d4-0000-4000-8000-000000000001'

describe('replaying one call', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({
			data: {
				request: { method: 'POST', endpoint: '/notificaties' },
				versions: { recorded: '3', current: '4', differ: true },
			},
		})
	})

	it('previews what a replay would send before anything is sent', async () => {
		const wrapper = mount(CallReplayModal, {
			props: { open: true, callId: CALL },
		})
		await flushPromises()

		expect(get.mock.calls[0][0]).toBe(
			`/index.php/apps/integriq/api/calls/${CALL}/preview`,
		)
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="call-replay-request"]').text()).toContain(
			'/notificaties',
		)
		expect(wrapper.vm.mappingVersion).toBe('3')
		expect(wrapper.find('[data-testid="call-replay-versions"]').exists()).toBe(
			true,
		)
	})

	it('a dry run posts dryRun and says nothing was sent', async () => {
		post.mockResolvedValue({
			data: {
				succeeded: 1,
				failed: 0,
				items: [
					{
						call: CALL,
						sent: false,
						succeeded: true,
						request: { method: 'POST' },
					},
				],
			},
		})
		const wrapper = mount(CallReplayModal, {
			props: { open: true, callId: CALL },
		})
		await flushPromises()

		await wrapper.vm.run(true)
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			`/index.php/apps/integriq/api/calls/${CALL}/replay`,
			{ dryRun: true, mappingVersion: '3' },
		)
		expect(wrapper.find('[data-testid="call-replay-outcome"]').text()).toContain(
			'Nothing was sent',
		)
		expect(wrapper.emitted('replayed')).toBeUndefined()
	})

	it('a replay under the current version sends that version and reports the answer', async () => {
		post.mockResolvedValue({
			data: {
				succeeded: 1,
				failed: 0,
				items: [
					{
						call: CALL,
						sent: true,
						succeeded: true,
						statusCode: 202,
						mappingVersion: '4',
					},
				],
			},
		})
		const wrapper = mount(CallReplayModal, {
			props: { open: true, callId: CALL },
		})
		await flushPromises()

		wrapper.vm.mappingVersion = '4'
		await wrapper.vm.run(false)
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			`/index.php/apps/integriq/api/calls/${CALL}/replay`,
			{ dryRun: false, mappingVersion: '4' },
		)
		expect(wrapper.find('[data-testid="call-replay-outcome"]').text()).toContain(
			'version 4',
		)
		expect(wrapper.emitted('replayed')).toHaveLength(1)
	})
})

describe('replaying several failed calls', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({
			data: {
				results: [
					{
						'@self': { id: 'call-1' },
						target: 'stuf-partner',
						statusCode: 503,
						direction: 'outbound',
					},
					{
						'@self': { id: 'call-2' },
						target: 'zgw-partner',
						statusCode: 0,
						direction: 'outbound',
					},
					{
						'@self': { id: 'call-3' },
						target: 'ok-partner',
						statusCode: 200,
						direction: 'outbound',
					},
					{
						'@self': { id: 'call-4' },
						target: 'inbound',
						statusCode: 500,
						direction: 'inbound',
					},
				],
			},
		})
	})

	it('lists only the outbound calls that failed, all selected', async () => {
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()

		expect(get.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/integriq/call_log',
		)
		expect(get.mock.calls[0][1]).toEqual({
			params: { _limit: 100, '_order[created]': 'desc' },
		})
		expect(wrapper.vm.selected).toEqual(['call-1', 'call-2'])
	})

	it('replays the selection in one request and shows an outcome per call', async () => {
		post.mockResolvedValue({
			data: {
				succeeded: 1,
				failed: 1,
				items: [
					{ call: 'call-1', succeeded: true, sent: true, statusCode: 200 },
					{
						call: 'call-2',
						succeeded: false,
						sent: false,
						detail: 'No source "zgw-partner" to call.',
					},
				],
			},
		})
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()

		await wrapper.vm.replay()
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/calls/replay',
			{ dryRun: false, calls: ['call-1', 'call-2'] },
		)
		const outcomes = wrapper
			.findAll('[data-testid="call-bulk-replay-item-outcome"]')
			.map((node) => node.text())
		expect(outcomes).toHaveLength(2)
		expect(outcomes[1]).toContain('No source')
		expect(wrapper.find('[data-testid="call-bulk-replay-summary"]').text()).toBe(
			'1 sent, 1 failed.',
		)
	})

	it('a call unchecked is not replayed', async () => {
		post.mockResolvedValue({
			data: {
				succeeded: 1,
				failed: 0,
				items: [{ call: 'call-2', succeeded: true, statusCode: 200 }],
			},
		})
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()

		wrapper.vm.toggle('call-1', false)
		await wrapper.vm.replay()

		expect(post).toHaveBeenCalledWith(
			`/index.php/apps/integriq/api/calls/call-2/replay`,
			{ dryRun: false },
		)
	})
})

describe('firing a call by hand', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({
			data: {
				results: [{ '@self': { id: 'src-1' }, name: 'ZGW Notificaties' }],
			},
		})
	})

	it('sends the chosen source, method, endpoint and parsed body', async () => {
		post.mockResolvedValue({
			data: {
				kind: 'dry-run',
				sent: false,
				succeeded: true,
				request: { method: 'POST' },
			},
		})
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()

		expect(wrapper.vm.sourceOptions).toEqual([
			{ id: 'src-1', label: 'ZGW Notificaties' },
		])
		wrapper.vm.selectedSource = wrapper.vm.sourceOptions[0]
		wrapper.vm.endpoint = ' /notificaties '
		wrapper.vm.body = '{"kanaal":"zaken"}'
		await wrapper.vm.fire(true)
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/calls/fire',
			{
				target: 'src-1',
				request: {
					method: 'POST',
					endpoint: '/notificaties',
					body: { kanaal: 'zaken' },
				},
				dryRun: true,
			},
		)
		expect(wrapper.find('[data-testid="call-fire-outcome"]').text()).toContain(
			'Nothing was sent',
		)
	})

	it('refuses a body that is not JSON before anything is sent', async () => {
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()

		wrapper.vm.selectedSource = { id: 'src-1', label: 'ZGW Notificaties' }
		wrapper.vm.body = '{kanaal: zaken'
		await wrapper.vm.$nextTick()
		await wrapper.vm.fire(false)

		expect(wrapper.vm.canFire).toBe(false)
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('The body is not valid JSON.')
	})
})

describe('the row actions', () => {
	it('offer a replay on an outbound call', () => {
		const wrapper = mount(CallLogRowActions, {
			props: { row: { '@self': { id: CALL }, direction: 'outbound' } },
		})
		expect(wrapper.find('[data-testid="call-log-row-replay"]').exists()).toBe(
			true,
		)
	})

	it('offer nothing on an inbound request, which there is nothing to replay of', () => {
		const wrapper = mount(CallLogRowActions, {
			props: { row: { '@self': { id: CALL }, direction: 'inbound' } },
		})
		expect(wrapper.find('[data-testid="call-log-row-replay"]').exists()).toBe(
			false,
		)
	})
})

describe('the SourceLogs page wiring', () => {
	it('mounts the row actions and the page actions from the registry', () => {
		const root = join(__dirname, '..', '..')
		const read = (relative) =>
			JSON.parse(readFileSync(join(root, relative), 'utf8'))
		const merged = buildManifest(
			read('src/manifest.json'),
			[],
			read('src/menu-layout.json'),
		)
		const page = merged.pages.find((candidate) => candidate.id === 'SourceLogs')

		expect(page.slots['row-actions']).toBe('CallLogRowActions')
		expect(page.actionsComponent).toBe('CallLogActions')

		// Importing the registry pulls in the whole published component
		// library, so its wiring is read from the source: both components are
		// imported from their files and exported under the names the manifest
		// uses.
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')
		expect(registry).toContain(
			"import CallLogRowActions from './components/callLog/CallLogRowActions.vue'",
		)
		expect(registry).toContain(
			"import CallLogActions from './components/callLog/CallLogActions.vue'",
		)
		expect(registry).toMatch(/^\tCallLogRowActions,$/m)
		expect(registry).toMatch(/^\tCallLogActions,$/m)
	})
})
