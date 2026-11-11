/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for approve() and reject() of the approval detail page
 * (src/views/Approvals/ApprovalDetail.vue).
 *
 * Both run on a plain context for every comment and server answer. Each run
 * records the requests, toasts, route changes and the busy flag; the
 * snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { post: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url) => {
			trace.push(['get', url])
			return Promise.resolve({ data: { id: 'r-1' } })
		},
		post: (url, body) => {
			trace.push(['post', url, body])
			return server.post === null ? Promise.resolve({ data: {} }) : Promise.reject(server.post)
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
	showWarning: (text) => trace.push(['warning', text]),
}))

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {} }))

const page = (await import('@/views/Approvals/ApprovalDetail.vue')).default

/**
 * A plain context carrying the page's data, computed and methods.
 *
 * @param {string} comment The comment typed.
 * @return {object} The context.
 */
function context(comment) {
	const ctx = { ...page.data(), comment, $route: { params: { id: 'r-1' } } }
	ctx.uid = 0
	ctx.$router = { push: (to) => trace.push(['route', to]) }
	for (const [name, fn] of Object.entries(page.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(page.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx) })
	}
	return ctx
}

const ANSWERS = {
	ok: null,
	superseded: { response: { status: 409, data: { _approval: { supersededBy: 'r-2' } } } },
	'409 without successor': { response: { status: 409, data: { error: 'conflict' } } },
	'superseded but 500': { response: { status: 500, data: { _approval: { supersededBy: 'r-3' } } } },
	'server error text': { response: { status: 500, data: { error: 'boom' } } },
	'network message': { message: 'Network Error' },
	bare: {},
}

describe('approval decision golden', () => {
	for (const action of ['approve', 'reject']) {
		it(`${action}s as recorded`, async () => {
			const runs = []
			for (const comment of ['', '  ', 'looks good']) {
				for (const [name, answer] of Object.entries(ANSWERS)) {
					trace.length = 0
					server.post = answer
					const ctx = context(comment)
					const pending = ctx[action]()
					const busyDuring = ctx.busy
					await pending
					runs.push({ comment, answer: name, busyDuring, busyAfter: ctx.busy, trace: [...trace] })
				}
			}
			expect(runs).toMatchSnapshot()
		})
	}
})
