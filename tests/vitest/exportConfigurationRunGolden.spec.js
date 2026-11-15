// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for runExport() of the configuration export dialog
 * (src/dialogs/ExportConfigurationDialog.vue).
 *
 * runExport runs on a plain context for each selection and server answer.
 * Each run records the request, the downloaded file (name, type and
 * content), the link lifecycle, toasts, the close and the error message;
 * the snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { post: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: { results: [] } }),
		post: (url) => {
			trace.push(['post', url])
			return server.post instanceof Error || server.post?.response ? Promise.reject(server.post) : Promise.resolve({ data: server.post })
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
}))

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcModal: {}, NcSelect: {}, NcNoteCard: {}, NcLoadingIcon: {} }))

globalThis.t = (app, text) => text

const dialog = (await import('@/dialogs/ExportConfigurationDialog.vue')).default

/**
 * A plain context carrying the dialog's data and methods.
 *
 * @param {object|null} selected The selected group.
 * @return {object} The context.
 */
function context(selected) {
	const ctx = { ...dialog.data(), selected }
	for (const [name, fn] of Object.entries(dialog.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.close = () => trace.push(['close'])
	ctx.$emit = (...args) => trace.push(['emit', ...args])
	return ctx
}

/**
 * The text of a jsdom Blob.
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>} Its text.
 */
function readBlob(blob) {
	return new Promise((resolve) => {
		const reader = new FileReader()
		reader.onload = () => resolve(reader.result)
		reader.readAsText(blob)
	})
}

describe('configuration export golden', () => {
	it('exports as recorded', async () => {
		let blobs = 0
		const blobText = {}
		URL.createObjectURL = (blob) => {
			blobs++
			const href = `blob:${blobs}`
			blobText[href] = blob
			trace.push(['createObjectURL', href, blob.type])
			return href
		}
		URL.revokeObjectURL = (href) => trace.push(['revokeObjectURL', href, document.body.contains(document.querySelector(`a[href="${href}"]`))])
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			trace.push(['click', this.getAttribute('href'), this.download, document.body.contains(this)])
		})

		const answers = [
			{ name: 'ok', post: { configuration: { title: 'Zaken' }, secrets: '[redacted]' } },
			{ name: 'empty body', post: null },
			{ name: 'server error', post: { response: { data: { error: 'not allowed' } } } },
			{ name: 'network', post: new Error('Network Error') },
			{ name: 'bare', post: { response: {} } },
		]
		const runs = []
		for (const selected of [null, { id: 'cfg-1', label: 'Zaken' }]) {
			for (const answer of answers) {
				trace.length = 0
				server.post = answer.post
				const ctx = context(selected)
				const pending = ctx.runExport()
				const exportingDuring = ctx.exporting
				await pending
				const files = []
				for (const entry of trace) {
					if (entry[0] === 'createObjectURL') {
						files.push(await readBlob(blobText[entry[1]]))
					}
				}
				runs.push({ selected, answer: answer.name, exportingDuring, exporting: ctx.exporting, errorMessage: ctx.errorMessage, files, trace: [...trace], linksLeft: document.querySelectorAll('a').length })
			}
		}
		click.mockRestore()
		expect(runs).toMatchSnapshot()
	})
})
