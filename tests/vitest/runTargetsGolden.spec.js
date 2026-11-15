/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the run-action descriptors (src/modals/v2/runTargets.js).
 *
 * Every descriptor is rendered to plain data: its static fields as they are,
 * and each function field applied to a fixed set of rows, switch values and
 * run payloads. The snapshot pins the whole output, so a change in any title,
 * note, request, status banner, section or retry offer shows up as a diff.
 */

import { describe, expect, it } from 'vitest'
import {
	getRunDescriptor,
	initialOptionValues,
	visibleOptions,
} from '../../src/modals/v2/runTargets.js'

const PAIRS = [
	['synchronization', 'run'],
	['synchronization', 'test'],
	['job', 'run'],
	['job', 'test'],
]

const ITEMS = [
	{ id: 9 },
	{ uuid: 'u-1', syncMode: 'incremental' },
	{ id: 4, syncMode: 'full' },
	null,
]

const VALUES = [
	{},
	{ test: true },
	{ test: true, force: true },
	{ force: true, forceDeletion: true },
	{ forceRun: true },
]

const GUARDS = [
	null,
	{ guarded: false },
	{ guarded: true, reason: 'incremental_mode' },
	{ guarded: true, reason: 'fetch_incomplete' },
	{
		guarded: true,
		reason: 'ratio_threshold_exceeded',
		ratio: 0.4567,
		threshold: 0.25,
		candidateCount: 46,
		totalContracts: 100,
	},
	{ guarded: true, reason: 'ratio_threshold_exceeded', candidateCount: 3 },
	{ guarded: true, reason: 'ratio_threshold_exceeded', ratio: '0.5' },
	{ guarded: true, reason: 'ratio_threshold_exceeded' },
	{
		guarded: true,
		reason: 'ratio_threshold_exceeded',
		ratio: null,
		threshold: null,
		candidateCount: null,
	},
	{ guarded: true, reason: 'something_else' },
]

const PAYLOADS = [
	null,
	undefined,
	{},
	{ message: 'Success' },
	{ message: 'pending_approval' },
	{ message: 'Boom' },
	{ level: 'error', message: 'Job crashed' },
	{ level: 'ERROR' },
	{ level: 'warning' },
	{ level: 'Warning', message: 'Half done' },
	{ level: 'INFO', message: 'Done' },
	{
		message: 'Success',
		executionTime: 12.6,
		test: true,
		force: false,
		result: {
			type: 'push',
			contracts: ['a', null, '', 'b'],
			logs: ['l1'],
			objects: { found: 5, created: 2, deleted: 0 },
		},
	},
	{
		executionTime: '',
		force: true,
		result: { contracts: 'nope', objects: null },
	},
	{
		level: 'INFO',
		executionTime: 0,
		jobClass: 'OCA\\Job',
		lastRun: '2026-01-01',
		nextRun: '2026-01-02',
		stackTrace: { frame_0: 'one', frame_1: 2 },
	},
	{ stackTrace: {} },
	{
		result: {
			objects: {
				found: 1,
				skipped: 2,
				created: 3,
				updated: 4,
				deleted: 5,
				invalid: 6,
			},
		},
	},
	{ result: { objects: { skipped: '', invalid: false, found: null } } },
	...GUARDS.map((guard) => ({ result: { objects: { deletionGuard: guard } } })),
]

/**
 * Apply a function field to every input it may see, or copy a plain field.
 *
 * @param {string} key The descriptor field name.
 * @param {*} value The field value.
 * @return {*} Plain data.
 */
function render(key, value) {
	if (typeof value !== 'function') {
		return value
	}
	if (key === 'request') {
		return ITEMS.flatMap((item) => VALUES.map((values) => value(item, values)))
	}
	if (key === 'logsLink') {
		return ITEMS.map((item) => value(item))
	}
	if (key === 'disabledWhen') {
		return ITEMS.map((item) => value(item))
	}
	if (key === 'hiddenWhen') {
		return VALUES.map((values) => value(values))
	}

	return PAYLOADS.map((payload) => value(payload))
}

/**
 * Render a descriptor, its options included, to plain data.
 *
 * @param {object} descriptor The descriptor.
 * @return {object} Plain data.
 */
function plain(descriptor) {
	const out = {}
	for (const [key, value] of Object.entries(descriptor)) {
		if (key === 'options') {
			out.options = value.map((option) =>
				Object.fromEntries(
					Object.entries(option).map(([k, v]) => [k, render(k, v)]),
				),
			)
			continue
		}
		out[key] = render(key, value)
	}
	out.initialOptionValues = initialOptionValues(descriptor)
	out.visibleOptions = VALUES.map((values) =>
		visibleOptions(descriptor, values).map((option) => option.key),
	)

	return out
}

describe('run descriptors golden', () => {
	it.each(PAIRS)('%s/%s renders as recorded', (target, mode) => {
		expect(plain(getRunDescriptor(target, mode))).toMatchSnapshot()
	})
})
