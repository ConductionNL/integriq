/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for save() of the Berichtenbox settings
 * (src/views/admin/BerichtenboxSettings.vue).
 *
 * save() runs on a plain context for every combination of entered secrets
 * and server outcome. Each run records the request body, the toasts, the
 * field errors and the form state afterwards, so the snapshot pins which
 * secrets are sent and that they are cleared only after a successful save.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { outcome: 'ok' }

const ANSWERS = {
	ok: () =>
		Promise.resolve({
			data: {
				live: true,
				source: {
					senderOin: '0001',
					adapterToken: 'set',
					berichtTypes: { besluit: 'B1' },
				},
				warnings: ['certificate expires soon'],
			},
		}),
	'ok without source': () =>
		Promise.resolve({ data: { live: false, source: null } }),
	'field errors': () =>
		Promise.reject({
			response: {
				data: {
					fieldErrors: { senderOin: 'invalid' },
					errors: ['OIN invalid', 'CPA missing'],
				},
			},
		}),
	'no errors list': () =>
		Promise.reject({ response: { data: { errors: 'nope' } } }),
	'transport error': () => Promise.reject(new Error('Network Error')),
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: {} }),
		put: (url, body) => {
			trace.push(['put', url, structuredClone(body)])
			return ANSWERS[server.outcome]()
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
	showWarning: (text) => trace.push(['warning', text]),
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		url.replace(/\{(\w+)\}/g, (m, k) => (params && k in params ? params[k] : m)),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcPasswordField: {},
	NcTextField: {},
}))

const settings = (await import('@/views/admin/BerichtenboxSettings.vue')).default

const SECRETS = {
	none: { certificate: {}, adapterToken: '' },
	'blank certificate': {
		certificate: { certificatePem: '   ', privateKeyPem: 'KEY' },
		adapterToken: '',
	},
	certificate: {
		certificate: {
			certificatePem: 'PEM',
			privateKeyPem: 'KEY',
			passphrase: 'pw',
			caBundlePem: 'CA',
		},
		adapterToken: '',
	},
	token: { certificate: {}, adapterToken: 'tok' },
	both: {
		certificate: { certificatePem: 'PEM', privateKeyPem: 'KEY' },
		adapterToken: 'tok',
	},
}

/**
 * A plain context carrying the settings' data and methods.
 *
 * @param {object} secrets The entered certificate fields and adapter token.
 * @return {object} The context.
 */
function context(secrets) {
	const ctx = { t: (app, text) => text }
	Object.assign(ctx, settings.data.call(ctx))
	ctx.form.senderOin = '0001'
	ctx.certificate = { ...ctx.certificate, ...secrets.certificate }
	ctx.adapterToken = secrets.adapterToken
	ctx.fieldErrors = { stale: 'x' }
	for (const [name, fn] of Object.entries(settings.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('Berichtenbox settings save golden', () => {
	it.each(Object.keys(ANSWERS))(
		'saves as recorded when the server answers %s',
		async (outcome) => {
			const runs = []
			server.outcome = outcome
			for (const [name, secrets] of Object.entries(SECRETS)) {
				trace.length = 0
				const ctx = context(secrets)
				const pending = ctx.save()
				const savingDuring = ctx.saving
				await pending
				runs.push({
					name,
					savingDuring,
					savingAfter: ctx.saving,
					fieldErrors: ctx.fieldErrors,
					certificate: ctx.certificate,
					adapterToken: ctx.adapterToken,
					adapterTokenConfigured: ctx.adapterTokenConfigured,
					live: ctx.live,
					form: ctx.form,
					berichtTypes: ctx.berichtTypes,
					trace: [...trace],
				})
			}
			expect(runs).toMatchSnapshot()
		},
	)
})
