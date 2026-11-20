/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * connectors-course-marketplace on a live instance: the three provider sets
 * imported, a demo deployment per provider, and a marketplace run that stops
 * before it fetches anything while its deployment is not set (REQ-CMKT-001).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * `CourseMarketplaceSetsTest`, `RunPrerequisiteGuardTest` and
 * `SynchronizationServiceRunPrerequisiteTest` run the sets and the guard over
 * the fragment's JSON. Only an instance shows that the register import really
 * created the sources, mappings and synchronizations, and that the run route,
 * with the real engine and the real store, refuses a run whose deployment id is
 * empty and says in its log what to set.
 *
 * WHAT IT DOES NOT COVER, AND WHY. A run that imports courses needs a provider
 * catalogue the instance can reach. There is no mock catalogue source yet
 * (Task 6's mock source), and the real providers need contracts, so the
 * selection and retirement runs stay with PHPUnit
 * (`CourseMarketplaceSetsTest`, `SynchronizationServiceRetireValuesTest`).
 *
 * Read-only apart from the refused run: the run is answered before any fetch
 * or write, so there is nothing to clean up.
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { withRequestToken } from './support/requestToken.ts'

/** OpenRegister's objects in integriq's register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/integriq'

/** The three providers, by the slug their set shares. */
const PROVIDERS = ['go1', 'linkedin-learning', 'udemy-business']

/**
 * The objects of one schema, by slug.
 *
 * @param request The admin's request context.
 * @param schema  The schema.
 * @return The objects keyed by their slug.
 */
async function bySlug(request: APIRequestContext, schema: string): Promise<Record<string, any>> {
	const res = await request.get(`${OBJECTS}/${schema}?_limit=500`, { headers: { Accept: 'application/json' } })
	expect(res.ok(), `list ${schema} -> ${res.status()}`).toBe(true)
	const body = await res.json()
	const rows: any[] = Array.isArray(body) ? body : (body.results ?? [])
	const out: Record<string, any> = {}
	for (const row of rows) {
		const slug = String(row?.slug ?? row?.['@self']?.slug ?? '')
		if (slug !== '') {
			out[slug] = row
		}
	}
	return out
}

/**
 * The OpenRegister id of an object.
 *
 * @param row The object.
 * @return Its id.
 */
function idOf(row: any): string {
	return String(row?.id ?? row?.['@self']?.id ?? row?.uuid ?? '')
}

test.describe('Course marketplace connectors', () => {
	test('each provider ships a source, three mappings and three synchronizations', async ({ request }) => {
		const sources = await bySlug(request, 'source')
		const mappings = await bySlug(request, 'mapping')
		const synchronizations = await bySlug(request, 'synchronization')

		for (const provider of PROVIDERS) {
			const base = `course-marketplace-${provider}`
			expect(sources[base], `the ${provider} source is imported`).toBeTruthy()
			for (const part of ['course', 'lesson', 'placement']) {
				expect(mappings[`${base}-${part}`], `the ${provider} ${part} mapping is imported`).toBeTruthy()
				expect(synchronizations[`${base}-${part}`], `the ${provider} ${part} synchronization is imported`).toBeTruthy()
			}
		}
	})

	test('the demo data carries one deployment per provider, each on its own tool', async ({ request }) => {
		const deployments = await bySlug(request, 'lti_deployment')
		test.skip(
			deployments['lti-deployment-course-marketplace-go1'] === undefined,
			'the demo data (integriq_mock_register.json) is not loaded on this instance',
		)
		const tools = await bySlug(request, 'lti_tool')
		const toolIds = new Set<string>()
		for (const provider of PROVIDERS) {
			expect(deployments[`lti-deployment-course-marketplace-${provider}`], `a ${provider} deployment`).toBeTruthy()
			const tool = tools[`lti-tool-course-marketplace-${provider}`]
			expect(tool, `a ${provider} tool`).toBeTruthy()
			toolIds.add(idOf(tool))
		}
		expect(toolIds.size, 'three different tools').toBe(3)
	})

	test('a run without its deployment stops before fetching and says what to set', async ({ request }) => {
		const mapping = (await bySlug(request, 'mapping'))['course-marketplace-udemy-business-placement']
		expect(mapping, 'the Udemy Business placement mapping is imported').toBeTruthy()
		test.skip(
			String(mapping?.mapping?.openconnectorDeploymentId ?? '').trim() !== '',
			'this instance has the Udemy Business deployment set, so the run would call the provider',
		)

		const synchronization = (await bySlug(request, 'synchronization'))['course-marketplace-udemy-business-course']
		const run = await request.post(`/index.php/apps/integriq/api/synchronizations/${idOf(synchronization)}/run`, {
			headers: { ...(await withRequestToken(request)), 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
			data: {},
		})
		const text = await run.text()
		expect(run.status(), text).toBeLessThan(500)
		expect(text, 'the answer names what to set').toContain('openconnectorDeploymentId')
		expect(text).toContain('course-marketplace-udemy-business-placement')
		expect(text).toContain('Nothing was fetched or written')
	})
})
