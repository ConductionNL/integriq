/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo starter set button on the admin page: it posts once and shows the
 * counts, or says why it refused.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = { calls: [], answers: [] }

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (...args) => {
			http.calls.push(['post', ...args])
			const next = http.answers.shift() ?? { data: {} }
			return next.reject ? Promise.reject(next.reject) : Promise.resolve(next)
		},
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
}))

const WooStarterSetSettings = (await import('@/views/admin/WooStarterSetSettings.vue')).default

/**
 * A component context with data, methods and a placeholder-filling t().
 *
 * @return {object} The context.
 */
function context() {
	const ctx = {
		t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m)),
	}
	Object.assign(ctx, WooStarterSetSettings.data.call(ctx))
	for (const [name, fn] of Object.entries(WooStarterSetSettings.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * A refused request with a problem code.
 *
 * @param {string} code The code.
 * @return {object} The answer.
 */
function refused(code) {
	return { reject: Object.assign(new Error('refused'), { response: { status: 409, data: { code } } }) }
}

beforeEach(() => {
	http.calls = []
	http.answers = []
})

describe('WooStarterSetSettings', () => {
	it('posts once and shows the created and skipped counts', async () => {
		const vm = context()
		http.answers = [{ data: { created: 16, skipped: 1 } }]

		await vm.run()

		expect(http.calls).toEqual([['post', '/apps/integriq/api/catalogi/woo-starter-set']])
		expect(vm.result).toEqual({ created: 16, skipped: 1 })
		expect(vm.error).toBe('')
		expect(vm.running).toBe(false)
	})

	it('says the category list cannot be read when the scheme is missing', async () => {
		const vm = context()
		http.answers = [refused('tooi-scheme-missing')]

		await vm.run()

		expect(vm.result).toBeNull()
		expect(vm.error).toBe('The list of Woo information categories cannot be read. Nothing was created.')
		expect(vm.running).toBe(false)
	})

	it('asks for a catalogue first when none is configured, and falls back for anything else', async () => {
		const vm = context()
		http.answers = [refused('catalogue-not-configured'), refused('something-else')]

		await vm.run()
		expect(vm.error).toBe('No catalogue is configured yet. Set one up first.')

		await vm.run()
		expect(vm.error).toBe('The Woo starter set could not be created.')
	})
})
