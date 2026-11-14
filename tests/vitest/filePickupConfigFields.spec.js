// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A synchronization whose source type is sftp or ftps is a file pickup, and
 * FilePickup reads its settings from `sourceConfig`. These tests mount the
 * real FilePickupConfigFields and the real SyncConfigWidget and check that
 * every key is written under the name and in the type the backend reads.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FilePickupConfigFields, {
	AFTER_VALUES,
} from '@/views/Synchronization/FilePickupConfigFields.vue'
import SyncConfigWidget from '@/views/Synchronization/SyncConfigWidget.vue'
import syncKindOptions from '@/mixins/syncKindOptions.js'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/dialogs', () => ({
	FilePickerType: { Choose: 1 },
	getFilePickerBuilder: () => ({}),
}))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) =>
		defineComponent({
			name,
			props: [
				'modelValue',
				'label',
				'inputLabel',
				'options',
				'id',
				'inputId',
				'error',
				'type',
			],
			emits: ['update:modelValue'],
			render() {
				return h('div', {
					class: name,
					'data-id': this.id || this.inputId,
				})
			},
		})
	return {
		NcTextField: field('NcTextField'),
		NcSelect: field('NcSelect'),
		NcButton: field('NcButton'),
		NcLoadingIcon: field('NcLoadingIcon'),
	}
})

// SyncConfigWidget's script calls the global `t` the app installs.
globalThis.t = translate

/**
 * Mount the pickup fields over a configuration.
 *
 * @param {object} config the sourceConfig
 * @return {object} the wrapper
 */
function mountFields(config = {}) {
	return mount(FilePickupConfigFields, { props: { config } })
}

/**
 * One stubbed control whose id ends with a suffix.
 *
 * @param {object} wrapper the wrapper
 * @param {string} name NcSelect or NcTextField
 * @param {string} suffix the id suffix
 * @return {object|undefined} the control
 */
function control(wrapper, name, suffix) {
	return wrapper
		.findAllComponents({ name })
		.find((c) => String(c.attributes('data-id')).endsWith(suffix))
}

/**
 * The last configuration emitted.
 *
 * @param {object} wrapper the wrapper
 * @return {object} the configuration
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:config')
	return events[events.length - 1][0]
}

describe('FilePickupConfigFields', () => {
	it('writes every key under the name FilePickup reads, keeping the rest', () => {
		const wrapper = mountFields({ unrelated: 'kept' })
		control(wrapper, 'NcTextField', '-path').vm.$emit(
			'update:modelValue',
			' outgoing ',
		)
		expect(lastEmitted(wrapper)).toEqual({ unrelated: 'kept', path: 'outgoing' })

		const next = mountFields({ unrelated: 'kept', path: 'outgoing' })
		control(next, 'NcTextField', '-pattern').vm.$emit(
			'update:modelValue',
			'besluiten-*.csv',
		)
		control(next, 'NcTextField', '-files-folder').vm.$emit(
			'update:modelValue',
			'Partner/besluiten',
		)
		expect(next.emitted('update:config').map((e) => e[0])).toEqual([
			{ unrelated: 'kept', path: 'outgoing', pattern: 'besluiten-*.csv' },
			{
				unrelated: 'kept',
				path: 'outgoing',
				filesFolder: 'Partner/besluiten',
			},
		])
	})

	it('offers exactly the after values FilePickup accepts, move by default', () => {
		const after = control(mountFields(), 'NcSelect', '-after')
		expect(after.props('options').map((o) => o.id)).toEqual([
			'move',
			'delete',
			'keep',
		])
		expect(AFTER_VALUES).toEqual(['move', 'delete', 'keep'])
		expect(after.props('modelValue').id).toBe('move')
	})

	it('asks for the archive folder only when the file is moved', () => {
		expect(control(mountFields(), 'NcTextField', '-archive-path')).toBeDefined()
		expect(
			control(
				mountFields({ after: 'delete' }),
				'NcTextField',
				'-archive-path',
			),
		).toBeUndefined()

		const wrapper = mountFields({ archivePath: 'processed' })
		control(wrapper, 'NcSelect', '-after').vm.$emit('update:modelValue', {
			id: 'keep',
		})
		expect(lastEmitted(wrapper)).toEqual({
			archivePath: 'processed',
			after: 'keep',
		})
	})

	it('marks the required folders while they are empty', () => {
		const empty = mountFields()
		expect(control(empty, 'NcTextField', '-files-folder').props('error')).toBe(
			true,
		)
		expect(control(empty, 'NcTextField', '-archive-path').props('error')).toBe(
			true,
		)

		const filled = mountFields({ filesFolder: 'In', archivePath: 'done' })
		expect(control(filled, 'NcTextField', '-files-folder').props('error')).toBe(
			false,
		)
		expect(control(filled, 'NcTextField', '-archive-path').props('error')).toBe(
			false,
		)
	})

	it('stores the stability window as whole non-negative seconds, and clears it', () => {
		const wrapper = mountFields({ stableSeconds: 60 })
		const seconds = control(wrapper, 'NcTextField', '-stable-seconds')
		expect(seconds.props('modelValue')).toBe('60')

		seconds.vm.$emit('update:modelValue', '90.7')
		expect(lastEmitted(wrapper).stableSeconds).toBe(90)
		seconds.vm.$emit('update:modelValue', '-5')
		expect(lastEmitted(wrapper).stableSeconds).toBe(0)
		seconds.vm.$emit('update:modelValue', 'abc')
		expect(wrapper.emitted('update:config')).toHaveLength(2)
		seconds.vm.$emit('update:modelValue', '')
		expect(lastEmitted(wrapper)).toEqual({})
	})
})

describe('the file pickup kinds in the synchronization editor', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue({
			data: { results: [{ id: 'src-1', name: 'Partner SFTP' }] },
		})
	})

	it('offers sftp and ftps as source kinds only', () => {
		const ctx = { draft: {}, tablesEnabled: false, formsEnabled: false }
		const typeOptions = syncKindOptions.computed.typeOptions.call(ctx)
		const sourceTypeOptions = syncKindOptions.computed.sourceTypeOptions.call({
			...ctx,
			typeOptions,
		})
		expect(sourceTypeOptions.map((o) => o.id)).toEqual(
			expect.arrayContaining(['sftp', 'ftps']),
		)
		expect(typeOptions.map((o) => o.id)).not.toContain('sftp')
	})

	it('shows the source picker and the pickup fields for an sftp source', async () => {
		const wrapper = mount(SyncConfigWidget, {
			props: {
				kind: 'source',
				type: 'sftp',
				sourceId: 'src-1',
				config: { filesFolder: 'In' },
			},
			global: { mocks: { t: translate } },
		})
		await flushPromises()

		const source = control(wrapper, 'NcSelect', '-pickup-source')
		expect(source.props('options')).toEqual([
			{ id: 'src-1', label: 'Partner SFTP' },
		])
		const fields = wrapper.findComponent(FilePickupConfigFields)
		expect(fields.props('config')).toEqual({ filesFolder: 'In' })

		fields.vm.$emit('update:config', { filesFolder: 'Out' })
		expect(wrapper.emitted('update:config')[0][0]).toEqual({
			filesFolder: 'Out',
		})
	})
})
