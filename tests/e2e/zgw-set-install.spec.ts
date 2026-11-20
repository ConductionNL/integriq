/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Installing a packaged ZGW set binds it to one schema, and a second set on that
 * schema is refused in the operator's own language (zgw-connectors-for-dossiq,
 * REQ-ZGWC-002, task 4).
 *
 * WHAT IS DRIVEN. There is no install screen: the installer is the admin
 * endpoint `POST /api/zgw-sets/{slug}/install`, so this drives it as an operator's
 * tooling would, as the admin. `zgw-zaken` is installed on a schema of this run,
 * then `zgw-besluiten` on the same schema must answer 409 with the Dutch refusal
 * naming `zgw-zaken`, because two sets on one schema overwrite each other on
 * every run while both report a healthy synchronization.
 *
 * WHAT IT LEAVES. An install rebinds the set's seeded synchronizations, and the
 * API has no uninstall. So the binding `zgw-zaken` had before the run is read
 * first and installed again afterwards; when it had none, the set stays bound to
 * this run's schema slug, which no object lives in. The admin's language is put
 * back to what it was.
 *
 * @e2e zgw-consumer-connectors::an-operator-binds-zaken-to-the-case-schema
 * @e2e zgw-consumer-connectors::a-second-set-on-the-same-schema-is-refused
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const SETS = '/index.php/apps/integriq/api/zgw-sets'
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const ADMIN_USER = process.env.NC_ADMIN_USER || process.env.NC_USER || 'admin'

/** A register and schema slug of this run only. */
const RUN = `e2e-zgw-${Date.now()}`
const TARGET = { register: RUN, schema: `${RUN}-zaak` }

/**
 * The admin's language setting, through the provisioning API.
 *
 * @param request The request context.
 *
 * @return The language, or '' when none is set.
 */
async function readLanguage(request: APIRequestContext): Promise<string> {
	const res = await request.get(`/ocs/v2.php/cloud/users/${ADMIN_USER}?format=json`, { headers: HEADERS })
	expect(res.ok(), `reading ${ADMIN_USER}: ${res.status()}`).toBe(true)
	return String((await res.json())?.ocs?.data?.language ?? '')
}

/**
 * Set the admin's language through the provisioning API.
 *
 * @param request The request context.
 * @param language The language.
 *
 * @return Promise<void>
 */
async function writeLanguage(request: APIRequestContext, language: string): Promise<void> {
	const res = await request.put(`/ocs/v2.php/cloud/users/${ADMIN_USER}?format=json`, {
		headers: HEADERS,
		form: { key: 'language', value: language },
	})
	expect(res.ok(), `setting the language to "${language}": ${res.status()} ${await res.text()}`).toBe(true)
}

/**
 * The binding of one set, `register/schema`, or null.
 *
 * @param request The request context.
 * @param slug The set.
 *
 * @return The binding.
 */
async function bindingOf(request: APIRequestContext, slug: string): Promise<string | null> {
	const res = await request.get(SETS, { headers: HEADERS })
	expect(res.ok(), `listing the sets: ${res.status()} ${await res.text()}`).toBe(true)
	const set = ((await res.json())?.results ?? []).find((row: { slug: string }) => row.slug === slug)
	expect(set, `${slug} must be one of the packaged sets`).toBeTruthy()
	return set.binding ?? null
}

/**
 * Install a set against a register and schema.
 *
 * @param request The request context.
 * @param slug The set.
 * @param target The register and schema.
 * @param target.register The register slug.
 * @param target.schema The schema slug.
 *
 * @return The status and body.
 */
async function install(
	request: APIRequestContext,
	slug: string,
	target: { register: string; schema: string },
): Promise<{ status: number; body: Record<string, any> }> {
	const res = await request.post(`${SETS}/${slug}/install`, { headers: HEADERS, data: target })
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

test.describe.configure({ mode: 'serial' })

test.describe('installing a ZGW set', () => {
	let language = ''
	let previousZaken: string | null = null

	test.beforeAll(async ({ request }) => {
		language = await readLanguage(request)
		previousZaken = await bindingOf(request, 'zgw-zaken')
	})

	test.afterAll(async ({ request }) => {
		await writeLanguage(request, language === '' ? 'en' : language)
		if (previousZaken !== null && previousZaken.includes('/') === true) {
			const [register, schema] = previousZaken.split('/')
			await install(request, 'zgw-zaken', { register, schema })
		}
	})

	// @e2e zgw-consumer-connectors::an-operator-binds-zaken-to-the-case-schema
	// @e2e zgw-consumer-connectors::a-second-set-on-the-same-schema-is-refused
	test('a second set on the same schema is refused in Dutch, naming the set that holds it', async ({ request }) => {
		const first = await install(request, 'zgw-zaken', TARGET)
		expect(first.status, `installing zgw-zaken: ${JSON.stringify(first.body)}`).toBe(200)
		expect(first.body.set).toBe('zgw-zaken')
		expect(first.body.binding).toBe(`${TARGET.register}/${TARGET.schema}`)
		expect((first.body.synchronizations ?? []).length, 'the set binds its seeded synchronizations').toBeGreaterThan(0)
		expect(await bindingOf(request, 'zgw-zaken')).toBe(`${TARGET.register}/${TARGET.schema}`)

		await writeLanguage(request, 'nl')
		const second = await install(request, 'zgw-besluiten', TARGET)
		expect(second.status, 'a second set on a bound schema is refused').toBe(409)
		expect(second.body.error).toContain('Dit schema is al gekoppeld aan "zgw-zaken"')
		expect(second.body.error).toContain('"zgw-besluiten"')
		expect(await bindingOf(request, 'zgw-besluiten'), 'the refused set binds nothing').not.toBe(`${TARGET.register}/${TARGET.schema}`)
	})

	test('a set without a register and schema is refused before anything is bound', async ({ request }) => {
		await writeLanguage(request, 'nl')
		const before = await bindingOf(request, 'zgw-documenten')
		const refused = await install(request, 'zgw-documenten', { register: '', schema: '' })
		expect(refused.status).toBe(409)
		expect(String(refused.body.error ?? '')).not.toBe('')
		expect(refused.body.error, 'the refusal is in the operator\'s language').not.toContain('This set needs a register')
		expect(await bindingOf(request, 'zgw-documenten')).toBe(before)
	})
})
