/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for RuleActionConfig (src/views/Rule/RuleActionConfig.vue), the
 * action half of the rule editor: which action type it reads, which form it
 * picks, and the configuration it emits when the type changes, a form writes
 * its slot, a mapping or flow id is picked, or raw JSON is typed. The component
 * logic runs over a plain context; emitted events are recorded.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcNoteCard: {}, NcSelect: {} }))
vi.mock('@/views/Rule/actionForms/ApprovalForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/AuthenticationForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/DownloadForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/ErrorForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/ExtendExternalInputForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/ExtendInputForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/FetchFileForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/FilepartsCreateForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/FilepartUploadForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/FlowForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/LockingForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/MappingForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/SaveObjectForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/SynchronizationForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/UploadForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/WebhookSignatureForm.vue', () => ({ default: {} }))
vi.mock('@/views/Rule/actionForms/WriteFileForm.vue', () => ({ default: {} }))

const config = (await import('@/views/Rule/RuleActionConfig.vue')).default

/**
 * A component context for the given props, with every computed as a getter,
 * every method bound and emitted events recorded.
 *
 * @param {object} configuration The rule's configuration blob.
 * @param {string} type The rule's top-level type.
 * @return {object} The context.
 */
function vm(configuration, type = '') {
	const ctx = {
		configuration,
		type,
		events: [],
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (match, key) => vars?.[key] ?? match),
	}
	ctx.$emit = (event, payload) => ctx.events.push([event, payload])
	Object.assign(ctx, config.data.call(ctx))
	for (const [name, getter] of Object.entries(config.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(config.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('reading the action type', () => {
	it('prefers configuration.type over the top-level type', () => {
		const form = vm({ type: 'download' }, 'upload')
		expect(form.actionType).toBe('download')
		expect(form.selectedTypeOption).toEqual({ id: 'download', label: 'Download' })
		expect(form.formComponent).toBe('DownloadForm')
	})

	it('falls back to the top-level type for a rule written before the picker', () => {
		const form = vm({}, 'save_object')
		expect(form.actionType).toBe('save_object')
		expect(form.formComponent).toBe('SaveObjectForm')
	})

	it('has no type, option or form when neither is set', () => {
		const form = vm(null)
		expect(form.actionType).toBe('')
		expect(form.selectedTypeOption).toBeNull()
		expect(form.formComponent).toBeNull()
	})

	it('has no form for a type without one, such as javascript', () => {
		const form = vm({ type: 'javascript' })
		expect(form.formComponent).toBeNull()
		expect(form.selectedTypeOption).toBeNull()
		expect(form.rawEditorLabel).toBe('Raw configuration for javascript')
	})

	it('offers one option per action type, translated', () => {
		const ids = vm({}).typeOptions.map((option) => option.id)
		expect(ids).toContain('mapping')
		expect(ids).toContain('flow')
		expect(ids).toContain('webhook_signature')
		expect(ids).not.toContain('javascript')
	})
})

describe('the slot of the active type', () => {
	it('is the object under the type key', () => {
		expect(vm({ type: 'locking', locking: { action: 'lock' } }).slotValue).toEqual({ action: 'lock' })
	})

	it('is an empty object when the slot is missing or not an object', () => {
		expect(vm({ type: 'locking' }).slotValue).toEqual({})
		expect(vm({ type: 'locking', locking: 'x' }).slotValue).toEqual({})
	})
})

describe('the raw draft', () => {
	it('is empty when the slot is unset', () => {
		expect(vm({ type: 'javascript' }).rawDraft).toBe('')
		expect(vm({ type: 'javascript', javascript: null }).rawDraft).toBe('')
	})

	it('shows a string slot verbatim and an object slot as indented JSON', () => {
		expect(vm({ type: 'javascript', javascript: 'return 1' }).rawDraft).toBe('return 1')
		expect(vm({ type: 'javascript', javascript: { a: 1 } }).rawDraft).toBe('{\n  "a": 1\n}')
	})

	it('falls back to String() for a slot JSON cannot serialise', () => {
		expect(vm({ type: 'javascript', javascript: 10n }).rawDraft).toBe('10')
	})

	it('keeps a typed draft over the stored slot', () => {
		const form = vm({ type: 'javascript', javascript: { a: 1 } })
		form.rawDrafts.javascript = '{"a": '
		expect(form.rawDraft).toBe('{"a": ')
	})
})

describe('picking a type', () => {
	it('rewrites only configuration.type and also emits the top-level type', () => {
		const form = vm({ type: 'download', download: { url: 'x' } })
		form.onTypePick({ id: 'upload', label: 'Upload' })
		expect(form.events).toEqual([
			['update', { type: 'upload', download: { url: 'x' } }],
			['update:type', 'upload'],
		])
	})

	it('starts from an empty configuration when there is none', () => {
		const form = vm(null)
		form.onTypePick({ id: 'error' })
		expect(form.events[0]).toEqual(['update', { type: 'error' }])
	})

	it('ignores a cleared pick', () => {
		const form = vm({ type: 'error' })
		form.onTypePick(null)
		expect(form.events).toEqual([])
	})
})

describe('writing a form slot', () => {
	it('replaces the slot of the active type and keeps the others', () => {
		const form = vm({ type: 'locking', locking: { a: 1 }, error: { code: 500 } })
		form.onSlotUpdate({ b: 2 })
		expect(form.events).toEqual([['update', { type: 'locking', locking: { b: 2 }, error: { code: 500 } }]])
	})
})

describe('mapping and flow ids', () => {
	it('stores a picked mapping id as a string', () => {
		const form = vm({ type: 'mapping' })
		form.onMappingIdUpdate(42)
		expect(form.events).toEqual([['update', { type: 'mapping', mapping: '42' }]])
	})

	it('drops the mapping key when the pick is cleared', () => {
		const form = vm({ type: 'mapping', mapping: '42' })
		form.onMappingIdUpdate('')
		expect(form.events).toEqual([['update', { type: 'mapping' }]])
	})

	it('stores a picked flow id as a string', () => {
		const form = vm(null)
		form.onFlowIdUpdate(7)
		expect(form.events).toEqual([['update', { flow: '7' }]])
	})

	it('drops the flow key when the pick is cleared', () => {
		const form = vm({ type: 'flow', flow: '7' })
		form.onFlowIdUpdate('')
		expect(form.events).toEqual([['update', { type: 'flow' }]])
	})
})

describe('raw JSON input', () => {
	it('parses valid JSON into the slot and clears the error', () => {
		const form = vm({ type: 'javascript' })
		form.rawError = 'old'
		form.onRawInput(' {"a": [1, 2]} ')
		expect(form.rawError).toBe('')
		expect(form.rawDrafts.javascript).toBe(' {"a": [1, 2]} ')
		expect(form.events).toEqual([['update', { type: 'javascript', javascript: { a: [1, 2] } }]])
	})

	it('removes the slot on empty input', () => {
		const form = vm({ type: 'javascript', javascript: { a: 1 } })
		form.rawError = 'old'
		form.onRawInput('   ')
		expect(form.rawError).toBe('')
		expect(form.events).toEqual([['update', { type: 'javascript' }]])
	})

	it('keeps the configuration and shows the parse error on invalid JSON', () => {
		const form = vm({ type: 'javascript', javascript: { a: 1 } })
		form.onRawInput('{"a": ')
		expect(form.events).toEqual([])
		expect(form.rawError).toMatch(/^Invalid JSON: /)
		expect(form.rawDraft).toBe('{"a": ')
	})
})
