/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md
 * (REQ-SFTP-003, a folder in Nextcloud Files is a watched source; the scenario
 * "a file dropped in the folder becomes a draft publication").
 *
 * What an officer does here: a synchronization watches a folder in the Files
 * of the admin, with `target: mapping` onto opencatalogi's `publication`
 * schema and `after: move`. The officer saves `besluit-<run>.pdf` into that
 * folder. Once the queued ingest job has run, one publication exists with the
 * title mapped from the file name, no publication date and the PDF attached,
 * and the file has moved to the `processed` folder beside it.
 *
 * The source and the synchronization are staged through OpenRegister's object
 * API (`/index.php/apps/openregister/api/objects/integriq/...`), the same rows
 * the Sources and Synchronizations pages write. The file is saved through
 * WebDAV (`/remote.php/dav/files/<user>/...`), the protocol the Files app
 * uploads with, so the server fires the same `NodeCreatedEvent`. The ingest
 * itself is a queued background job: the test waits for the publication for
 * up to five minutes, which needs the instance's background jobs to run
 * (cron, or the e2e seed's job runner).
 *
 * The other scenarios of the requirement are excluded from e2e and covered by
 * PHPUnit: once per file (WatchedFileIngestJobTest), the sweep
 * (WatchedFolderSweepTest), the listener only queueing (WatchedFolderListenerTest)
 * and the publication date stripped (WatchedFileIngestJobTest).
 *
 * Skipped when opencatalogi's publication schema is not on the instance: the
 * scenario's target is that schema. Runs as the admin the global setup logs
 * in. Everything is seeded under this run's marker and deleted afterwards.
 *
 * Written from the scenario before tasks 6, 7 and 9 of the change are built
 * (the listener, the ingest job and the seed); not run yet.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'

/** One run's marker, so reruns never meet each other's objects or folders. */
const RUN = `e2e-watch-${Date.now().toString(36)}`

/** The watched folder in the admin's Files. */
const FOLDER = `${RUN}/inkomend`

/** The file the officer drops. */
const FILE_NAME = `besluit-${RUN}.pdf`

/** A minimal PDF, enough for a mimetype of application/pdf. */
const PDF =
	'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n'

const cleanup: Array<{ register: string; schema: string; id: string }> = []

/**
 * Headers for an authenticated OCS or WebDAV call as the logged-in admin.
 *
 * @param request The request fixture.
 * @return The headers.
 */
async function adminHeaders(
	request: APIRequestContext,
): Promise<Record<string, string>> {
	return { 'OCS-APIRequest': 'true', ...(await withRequestToken(request)) }
}

/**
 * The id of a stored object, whichever key carries it.
 *
 * @param row The object as the API returned it.
 * @return Its id.
 */
function idOf(row: Record<string, any>): string {
	return String(row.id ?? row.uuid ?? row['@self']?.id ?? '')
}

/**
 * Create one object through OpenRegister's object API and remember it for cleanup.
 *
 * @param request  The request fixture.
 * @param register The register slug.
 * @param schema   The schema slug.
 * @param data     The object.
 * @return The object's id.
 */
async function seedObject(
	request: APIRequestContext,
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${register}/${schema}`, {
		failOnStatusCode: false,
		headers: await adminHeaders(request),
		data,
	})
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(
		resp.status(),
	)
	const id = idOf(await resp.json())
	expect(id, `the seeded ${schema} has an id`).not.toBe('')
	cleanup.push({ register, schema, id })

	return id
}

/**
 * The publications whose title carries this run's marker.
 *
 * @param request The request fixture.
 * @return The publications found.
 */
async function publicationsOfThisRun(
	request: APIRequestContext,
): Promise<Array<Record<string, any>>> {
	const resp = await request.get(
		`${OR_BASE}/publication/publication?_search=${encodeURIComponent(RUN)}`,
		{
			failOnStatusCode: false,
			headers: await adminHeaders(request),
		},
	)
	if (resp.status() !== 200) {
		return []
	}

	const body = await resp.json()
	const rows: Array<Record<string, any>> = Array.isArray(body)
		? body
		: (body.results ?? [])

	return rows.filter((row) => String(row.title ?? '').includes(RUN))
}

/**
 * Whether a path exists in the admin's Files.
 *
 * @param request The request fixture.
 * @param path    The path, relative to the admin's Files.
 * @return True when WebDAV finds it.
 */
async function existsInFiles(
	request: APIRequestContext,
	path: string,
): Promise<boolean> {
	const resp = await request.fetch(`/remote.php/dav/files/${ADMIN_USER}/${path}`, {
		method: 'PROPFIND',
		headers: { ...(await adminHeaders(request)), Depth: '0' },
		failOnStatusCode: false,
	})

	return resp.status() === 207
}

test.describe('sources-sftp-adapter-watched-folder: a watched folder in Nextcloud Files', () => {
	let syncId = ''

	test.beforeAll(async ({ request }) => {
		const probe = await request.get(
			`${OR_BASE}/publication/publication?_limit=1`,
			{ failOnStatusCode: false, headers: await adminHeaders(request) },
		)
		test.skip(
			probe.status() !== 200,
			"opencatalogi's publication schema is not on this instance",
		)

		const headers = await adminHeaders(request)
		for (const folder of [RUN, FOLDER]) {
			const made = await request.fetch(
				`/remote.php/dav/files/${ADMIN_USER}/${folder}`,
				{ method: 'MKCOL', headers, failOnStatusCode: false },
			)
			expect(made.status(), `creating ${folder}`).toBeLessThan(300)
		}

		const sourceId = await seedObject(request, 'integriq', 'source', {
			name: `E2E watched folder ${RUN}`,
			type: 'nextcloud-folder',
			location: '',
			isEnabled: true,
			configuration: { ownerUid: ADMIN_USER, rootPath: FOLDER },
		})
		const mappingId = await seedObject(request, 'integriq', 'mapping', {
			name: `E2E watched folder mapping ${RUN}`,
			mapping: {
				title: '{{ name }}',
				summary: 'Received in a watched folder',
			},
		})
		syncId = await seedObject(request, 'integriq', 'synchronization', {
			name: `E2E watched folder ${RUN}`,
			description: 'Seeded by tests/e2e/watched-folder.spec.ts',
			sourceId,
			sourceType: 'nextcloud-folder',
			sourceConfig: {
				pattern: '*.pdf',
				target: 'mapping',
				after: 'move',
				processedPath: 'processed',
				failedPath: 'failed',
				stableSeconds: 0,
			},
			sourceTargetMapping: mappingId,
			targetType: 'register/schema',
			targetId: 'publication/publication',
			status: 'enabled',
		})
	})

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		for (const row of await publicationsOfThisRun(request)) {
			await request.delete(`${OR_BASE}/publication/publication/${idOf(row)}`, {
				failOnStatusCode: false,
				headers,
			})
		}
		for (const { register, schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${register}/${schema}/${id}`, {
				failOnStatusCode: false,
				headers,
			})
		}
		await request.delete(`/remote.php/dav/files/${ADMIN_USER}/${RUN}`, {
			failOnStatusCode: false,
			headers,
		})
	})

	// @e2e data-infra-connectors::a-file-dropped-in-the-folder-becomes-a-draft-publication
	test('a PDF saved into the watched folder becomes one draft publication and moves to processed', async ({
		page,
		request,
	}) => {
		test.setTimeout(6 * 60_000)

		// The officer saves the PDF into the watched folder.
		const put = await request.put(
			`/remote.php/dav/files/${ADMIN_USER}/${FOLDER}/${FILE_NAME}`,
			{
				headers: {
					...(await adminHeaders(request)),
					'Content-Type': 'application/pdf',
				},
				data: PDF,
				failOnStatusCode: false,
			},
		)
		expect(put.status(), await put.text()).toBeLessThan(300)

		// The queued ingest job runs in the background; wait for its publication.
		await expect
			.poll(async () => (await publicationsOfThisRun(request)).length, {
				message: 'one publication for the dropped file',
				timeout: 5 * 60_000,
				intervals: [5_000, 10_000, 15_000],
			})
			.toBe(1)

		const [publication] = await publicationsOfThisRun(request)
		expect(publication.title).toBe(FILE_NAME)
		expect(
			publication.published ?? null,
			'a watched-folder run never publishes',
		).toBeNull()
		expect(publication.publicatiedatum ?? null).toBeNull()

		// The PDF is attached to the publication.
		const files = await request.get(
			`${OR_BASE}/publication/publication/${idOf(publication)}/files`,
			{
				failOnStatusCode: false,
				headers: await adminHeaders(request),
			},
		)
		expect(files.status()).toBe(200)
		const fileBody = await files.json()
		const attached: Array<Record<string, any>> = Array.isArray(fileBody)
			? fileBody
			: (fileBody.results ?? [])
		expect(
			attached.map((file) => String(file.title ?? file.name ?? '')),
		).toContain(FILE_NAME)

		// The file has moved to processed, only after the safe write.
		expect(
			await existsInFiles(request, `${FOLDER}/processed/${FILE_NAME}`),
			'the file is in processed',
		).toBe(true)
		expect(
			await existsInFiles(request, `${FOLDER}/${FILE_NAME}`),
			'the file left the watched folder',
		).toBe(false)

		// The synchronization the officer configured opens on its page.
		await gotoAppRoute(page, `/synchronizations/${syncId}`)
		await expectRouteMatched(page, `/synchronizations/${syncId}`)
		await expect(
			page.getByText(`E2E watched folder ${RUN}`).first(),
		).toBeVisible({ timeout: 30_000 })
	})
})
