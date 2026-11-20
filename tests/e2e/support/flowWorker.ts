/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Drive OpenRegister's FlowRunWorker from a spec.
 *
 * `POST /api/flows/{id}/run` only enqueues a run; the FlowRunWorker background
 * job is what executes it. A spec that waited for cron would be measuring the
 * instance's scheduler, so the worker is executed explicitly through occ.
 *
 * occ is resolved, never guessed, in this order:
 *  1. `OCC_CMD`, a full command prefix (e.g. `docker exec -u www-data nc php occ`);
 *  2. an `occ` file above this checkout that can see the integriq commands
 *     (the CI layout, where the app is checked out inside the server tree);
 *  3. a running container named in `NC_CONTAINER` / `INTEGRIQ_E2E_CONTAINER`.
 * There is no default container name: the shared dev container must never be
 * written to by accident. When none resolves the helper throws, so a spec
 * cannot pass by skipping its own subject.
 *
 * The same resolution lives inline in spec-coverage/flow-native-sync.spec.ts;
 * that file documents why each candidate needs a functional check.
 */

import { execFileSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'

/** How occ is reached, and a way to run it. */
export interface OccRunner {
	/** Human-readable description of the invocation, for failure messages. */
	how: string
	/** Run occ with these arguments and return stdout. */
	run: (args: string[]) => string
}

let resolved: OccRunner | null = null

const BUFFER = 32 * 1024 * 1024

/**
 * Whether this occ answers for an installed Nextcloud with integriq enabled.
 *
 * @param candidate Absolute path to the occ file.
 * @param dir The directory to run it from.
 *
 * @return True when `occ list integriq` lists the app's commands.
 */
function occAnswers(candidate: string, dir: string): boolean {
	try {
		const listed = execFileSync('php', [candidate, 'list', 'integriq'], {
			encoding: 'utf8',
			cwd: dir,
			maxBuffer: BUFFER,
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		return listed.includes('integriq:')
	} catch {
		return false
	}
}

/**
 * Resolve the occ invocation for this environment.
 *
 * @return The runner.
 */
export function occ(): OccRunner {
	if (resolved !== null) {
		return resolved
	}

	const override = (process.env.OCC_CMD ?? '').trim()
	if (override !== '') {
		const parts = override.split(/\s+/)
		resolved = {
			how: `OCC_CMD=${override}`,
			run: (args) =>
				execFileSync(parts[0], [...parts.slice(1), ...args], {
					encoding: 'utf8',
					maxBuffer: BUFFER,
				}),
		}
		return resolved
	}

	let dir = path.resolve(__dirname, '..', '..', '..')
	for (let up = 0; up < 6; up++) {
		const candidate = path.join(dir, 'occ')
		if (fs.existsSync(candidate) === true && occAnswers(candidate, dir) === true) {
			const cwd = dir
			resolved = {
				how: `php ${candidate}`,
				run: (args) =>
					execFileSync('php', [candidate, ...args], {
						encoding: 'utf8',
						cwd,
						maxBuffer: BUFFER,
					}),
			}
			return resolved
		}
		dir = path.dirname(dir)
	}

	const container = (
		process.env.NC_CONTAINER
		?? process.env.INTEGRIQ_E2E_CONTAINER
		?? process.env.NEXTCLOUD_CONTAINER
		?? ''
	).trim()
	let running = ''
	if (container !== '') {
		try {
			running = execFileSync(
				'docker',
				['inspect', '-f', '{{.State.Running}}', container],
				{ encoding: 'utf8' },
			).trim()
		} catch {
			running = ''
		}
	}
	if (running === 'true') {
		resolved = {
			how: `docker exec -u www-data ${container} php occ`,
			run: (args) =>
				execFileSync(
					'docker',
					['exec', '-u', 'www-data', container, 'php', 'occ', ...args],
					{ encoding: 'utf8', maxBuffer: BUFFER },
				),
		}
		return resolved
	}

	throw new Error(
		'occ could not be resolved: no OCC_CMD, no answering `occ` within six levels above '
			+ `${__dirname}, and no running container named in NC_CONTAINER ("${container}"). `
			+ "This spec drives OpenRegister's FlowRunWorker through occ; set OCC_CMD or "
			+ 'NC_CONTAINER rather than skipping.',
	)
}

/**
 * Decode occ's JSON output, ignoring warnings printed around it.
 *
 * @param raw The raw stdout.
 * @param command What produced it, for the failure message.
 *
 * @return The decoded document.
 */
export function parseOccJson(raw: string, command: string): unknown {
	const start = raw.search(/[[{]/)
	const end = Math.max(raw.lastIndexOf(']'), raw.lastIndexOf('}'))
	if (start < 0 || end < start) {
		throw new Error(
			`\`occ ${command}\` produced no JSON document (via ${occ().how}). `
				+ `It said: ${raw.slice(0, 800)}`,
		)
	}
	return JSON.parse(raw.slice(start, end + 1))
}

/**
 * The scheduled job id of OpenRegister's FlowRunWorker.
 *
 * @return The job id.
 */
export function flowRunWorkerJobId(): string {
	const raw = occ().run([
		'background-job:list',
		'--class=OCA\\OpenRegister\\BackgroundJob\\FlowRunWorker',
		'--output=json',
	])
	const rows = parseOccJson(raw, 'background-job:list') as Array<{ id: string }>
	if (rows.length !== 1) {
		throw new Error(
			`exactly one FlowRunWorker job must be scheduled (via ${occ().how}); occ reported `
				+ `${rows.length}. Without it a queued run is never picked up.`,
		)
	}
	return String(rows[0].id)
}

/**
 * Execute the FlowRunWorker once.
 *
 * @param jobId The scheduled job id.
 *
 * @return Nothing.
 */
export function executeFlowRunWorker(jobId: string): void {
	occ().run(['background-job:execute', jobId, '--force-execute'])
}
