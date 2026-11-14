/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Importing a saved .eml or .msg file from the inbox pages: the action is
 * offered only to a holder of the import right, the dialog posts the file
 * under the chosen mailbox, and a file that does not parse gets its reason.
 * Components run on a plain context; the HTTP client is mocked.
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcModal: {}, NcSelect: {} }))

const action = (await import('@/components/intake/MailImportAction.vue')).default
const modal = (await import('@/modals/ImportMailFileModal.vue')).default

/**
 * A plain context with a component's props, data, computed getters and methods.
 *
 * @param {object} component The component definition.
 * @param {object} props The props.
 * @return {object} The context.
 */
function context(component, props = {}) {
	const ctx = { ...props, $emit: vi.fn() }
	Object.assign(ctx, component.data?.call(ctx) ?? {})
	for (const [name, fn] of Object.entries(component.methods ?? {})) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, getter] of Object.entries(component.computed ?? {})) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	return ctx
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('MailImportAction', () => {
	it('is offered with the mailboxes when the user may import', async () => {
		axios.get.mockResolvedValue({ data: { allowed: true, mailboxes: [{ id: 'src-1', name: 'Postbus' }] } })
		const ctx = context(action)

		await ctx.loadOptions()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/integriq/api/mail-intake/import/options')
		expect(ctx.allowed).toBe(true)
		expect(ctx.mailboxes).toEqual([{ id: 'src-1', name: 'Postbus' }])
	})

	it('is not offered to a user without the right, nor when the question fails', async () => {
		axios.get.mockResolvedValueOnce({ data: { allowed: false, mailboxes: [] } })
		const refused = context(action)
		await refused.loadOptions()
		expect(refused.allowed).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('offline'))
		const failed = context(action)
		await failed.loadOptions()
		expect(failed.allowed).toBe(false)
	})
})

describe('ImportMailFileModal', () => {
	const file = new File(['From: a@example.org\r\n\r\nhoi'], 'melding.eml', { type: 'message/rfc822' })

	it('picks the only mailbox and needs a file before it imports', () => {
		const ctx = context(modal, { open: true, mailboxes: [{ id: 'src-1', name: 'Postbus' }] })
		ctx.reset()
		expect(ctx.sourceId).toBe('src-1')
		expect(ctx.canImport).toBe(false)
		ctx.onFilePicked({ target: { files: [file] } })
		expect(ctx.canImport).toBe(true)
	})

	it('posts the file under the chosen mailbox and reports the import', async () => {
		axios.post.mockResolvedValue({ data: { id: 'm1', message: { subject: 'Lantaarnpaal' }, warning: null } })
		const ctx = context(modal, { open: true, mailboxes: [{ id: 'src-1' }, { id: 'src-2' }] })
		ctx.reset()
		ctx.sourceId = 'src-2'
		ctx.file = file

		expect(await ctx.onImport()).toBe(true)

		const [url, form] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/integriq/api/mail-intake/import')
		expect(form.get('sourceId')).toBe('src-2')
		expect(form.get('file').name).toBe('melding.eml')
		expect(ctx.result).toBe('Imported "Lantaarnpaal".')
		expect(ctx.$emit).toHaveBeenCalledWith('imported', expect.objectContaining({ id: 'm1' }))
	})

	it('names the reason a file does not import and keeps the dialog open', async () => {
		axios.post.mockRejectedValue(Object.assign(new Error('x'), { response: { data: { error: 'The uploaded file was empty.' } } }))
		const ctx = context(modal, { open: true, mailboxes: [{ id: 'src-1' }] })
		ctx.reset()
		ctx.file = file

		expect(await ctx.onImport()).toBe(false)

		expect(ctx.error).toBe('The uploaded file was empty.')
		expect(ctx.$emit).not.toHaveBeenCalledWith('close')
		expect(ctx.busy).toBe(false)
	})
})
