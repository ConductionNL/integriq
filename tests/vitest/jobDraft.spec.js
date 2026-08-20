/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Jobs form helpers (src/modals/v2/jobDraft.js) and for the
 * cross-file consistency the Jobs form now depends on.
 *
 * Two reasons this suite carries more weight than usual:
 *
 *   1. `src/modals/v2/**` is silently unlinted — eslint.config.js intends to
 *      un-ignore it with `!src/modals/v2/**`, but `eslint src` prunes the
 *      `src/modals` directory before the negation can match. These tests are
 *      the only automated check over that code.
 *   2. The form's configuration is spread across three files on purpose (job
 *      schema fragment → field order + defaults, manifest → option list and
 *      layout groups, SFC → labels). That split is what makes the form
 *      declarative, but it also means a change in one file can silently
 *      contradict another. `FlowAction` going missing from the class picker
 *      while sitting in lib/Action/ is exactly that failure, so the last
 *      describe block pins the three files against each other.
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, it, expect } from 'vitest'
import {
	SYNCHRONIZATION_ACTION_CLASS,
	coerceNumber,
	dateValueFromStored,
	formatDateValue,
	groupFieldRuns,
	readSynchronizationId,
	writeSynchronizationId,
} from '../../src/modals/v2/jobDraft.js'

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')

describe('groupFieldRuns', () => {
	it('returns an empty list for anything that is not an array', () => {
		expect(groupFieldRuns(undefined)).toEqual([])
		expect(groupFieldRuns(null)).toEqual([])
		expect(groupFieldRuns('nope')).toEqual([])
	})

	it('emits one run per field, in order, when nothing is grouped', () => {
		const runs = groupFieldRuns([
			{ key: 'name' },
			{ key: 'description' },
			{ key: 'interval' },
		])
		expect(runs).toHaveLength(3)
		expect(runs.map((run) => run.fields[0].key)).toEqual([
			'name',
			'description',
			'interval',
		])
		expect(
			runs.every((run) => run.group === null && run.fields.length === 1),
		).toBe(true)
	})

	it('coalesces four consecutive same-group fields into a single run', () => {
		const runs = groupFieldRuns([
			{ key: 'interval' },
			{ key: 'timeSensitive', group: 'flags' },
			{ key: 'allowParallelRuns', group: 'flags' },
			{ key: 'isEnabled', group: 'flags' },
			{ key: 'singleRun', group: 'flags' },
			{ key: 'userId' },
		])
		expect(runs.map((run) => run.group)).toEqual([null, 'flags', null])
		expect(runs[1].fields.map((field) => field.key)).toEqual([
			'timeSensitive',
			'allowParallelRuns',
			'isEnabled',
			'singleRun',
		])
	})

	it('splits a non-contiguous group into separate runs rather than reordering', () => {
		// Consecutive-only is the contract: `order` stays the single source of
		// truth for sequence, so a split group must render as two visible runs
		// instead of silently teleporting a field back to its group.
		const runs = groupFieldRuns([
			{ key: 'timeSensitive', group: 'flags' },
			{ key: 'userId' },
			{ key: 'isEnabled', group: 'flags' },
		])
		expect(runs).toHaveLength(3)
		expect(runs.map((run) => run.fields[0].key)).toEqual([
			'timeSensitive',
			'userId',
			'isEnabled',
		])
	})

	it('does not merge two different adjacent groups', () => {
		const runs = groupFieldRuns([
			{ key: 'a', group: 'flags' },
			{ key: 'b', group: 'retention' },
		])
		expect(runs).toHaveLength(2)
	})

	it('treats an empty string or a non-string group as ungrouped', () => {
		const runs = groupFieldRuns([
			{ key: 'a', group: '' },
			{ key: 'b', group: 5 },
			{ key: 'c', group: null },
		])
		expect(runs).toHaveLength(3)
		expect(runs.every((run) => run.group === null)).toBe(true)
	})

	it('skips descriptors with no string key instead of breaking the v-for', () => {
		const runs = groupFieldRuns([
			{ key: 'name' },
			null,
			{},
			{ key: 42 },
			{ key: 'interval' },
		])
		expect(runs.map((run) => run.fields[0].key)).toEqual(['name', 'interval'])
	})

	it('emits unique keys, including when a group shares a field name', () => {
		const runs = groupFieldRuns([
			{ key: 'flags' },
			{ key: 'timeSensitive', group: 'flags' },
			{ key: 'isEnabled', group: 'flags' },
		])
		const keys = runs.map((run) => run.key)
		expect(new Set(keys).size).toBe(keys.length)
	})
})

describe('date round trip', () => {
	it('returns null for empty or unparseable input', () => {
		expect(dateValueFromStored(null)).toBeNull()
		expect(dateValueFromStored('')).toBeNull()
		expect(dateValueFromStored(undefined)).toBeNull()
		expect(dateValueFromStored('not a date')).toBeNull()
	})

	it('reads both the ISO and the space-separated persisted form as local time', () => {
		// The trailing Z is deliberately ignored — NcDateTimePickerNative is a
		// local-time control, and CnFormDialog's dateValueFor does the same.
		for (const raw of ['2026-10-15 14:30:00', '2026-10-15T14:30:00Z']) {
			const parsed = dateValueFromStored(raw)
			expect(parsed.getFullYear()).toBe(2026)
			expect(parsed.getMonth()).toBe(9)
			expect(parsed.getDate()).toBe(15)
			expect(parsed.getHours()).toBe(14)
			expect(parsed.getMinutes()).toBe(30)
		}
	})

	it('drops seconds — deliberately, to stay byte-identical to the library', () => {
		// CnFormDialog.normalizePersistedDates() has already applied this exact
		// lossy transform to formData before this form's slot renders. Parsing
		// seconds here would make an untouched field read as dirty on open, so
		// the loss is the correct behaviour, not an oversight.
		expect(dateValueFromStored('2026-10-15T14:30:45Z').getSeconds()).toBe(0)
	})

	it('serialises a date widget as a bare calendar date', () => {
		expect(formatDateValue('date', new Date(2026, 9, 15, 14, 30, 45))).toBe(
			'2026-10-15',
		)
	})

	it('serialises a datetime widget as RFC 3339 with seconds and an offset', () => {
		// The offset is mandatory: ajv-formats' `date-time` requires one and the
		// backend rejects a bare YYYY-MM-DDTHH:mm on save.
		const date = new Date(2026, 9, 15, 14, 30, 45)
		const serialised = formatDateValue('datetime', date)
		expect(serialised).toMatch(
			/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/,
		)
		expect(serialised.startsWith('2026-10-15T14:30:45')).toBe(true)

		// Compute the expected offset here rather than hardcoding one, so the
		// test passes in CI's UTC and on a developer machine in CEST alike.
		const offMin = -date.getTimezoneOffset()
		const sign = offMin >= 0 ? '+' : '-'
		const abs = Math.abs(offMin)
		const expected = `${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`
		expect(serialised.slice(-6)).toBe(expected)
	})

	it('round-trips to the same local minute', () => {
		const date = new Date(2026, 2, 29, 8, 5, 0)
		const back = dateValueFromStored(formatDateValue('datetime', date))
		expect(back.getFullYear()).toBe(date.getFullYear())
		expect(back.getMonth()).toBe(date.getMonth())
		expect(back.getDate()).toBe(date.getDate())
		expect(back.getHours()).toBe(date.getHours())
		expect(back.getMinutes()).toBe(date.getMinutes())
	})
})

describe('coerceNumber', () => {
	it('maps empty-ish input to null', () => {
		expect(coerceNumber('')).toBeNull()
		expect(coerceNumber(null)).toBeNull()
		expect(coerceNumber(undefined)).toBeNull()
	})

	it('returns a number, not the input string', () => {
		expect(coerceNumber('3600')).toBe(3600)
		expect(typeof coerceNumber('3600')).toBe('number')
	})

	it('preserves zero', () => {
		// A falsy check here would eat a legitimate 0.
		expect(coerceNumber('0')).toBe(0)
	})

	it('never yields NaN', () => {
		// NaN survives assignment and only degrades to null at JSON.stringify
		// time, so it would sit in formData and defeat min/max validation.
		expect(coerceNumber('abc')).toBeNull()
		expect(Number.isNaN(coerceNumber('abc'))).toBe(false)
	})
})

describe('synchronization argument round trip', () => {
	it('reads nothing out of a non-object or empty arguments value', () => {
		expect(readSynchronizationId(null)).toBeNull()
		expect(readSynchronizationId('a string')).toBeNull()
		expect(readSynchronizationId([])).toBeNull()
		expect(readSynchronizationId({})).toBeNull()
		expect(readSynchronizationId({ synchronizationId: '' })).toBeNull()
	})

	it('stringifies the id so it matches the picker option ids', () => {
		expect(readSynchronizationId({ synchronizationId: 42 })).toBe('42')
	})

	it('does not mutate the arguments object it is given', () => {
		// CnFormDialog tracks dirtiness against the item it cloned, so mutating
		// in place would change both sides of that comparison and lose the edit.
		const args = { foo: 'bar' }
		const snapshot = { ...args }
		const next = writeSynchronizationId(args, 'abc')
		expect(args).toEqual(snapshot)
		expect(next).not.toBe(args)
	})

	it('preserves unrelated arguments', () => {
		expect(writeSynchronizationId({ foo: 'bar' }, 'abc')).toEqual({
			foo: 'bar',
			synchronizationId: 'abc',
		})
	})

	it('removes the key when cleared, rather than storing null', () => {
		expect(
			writeSynchronizationId({ foo: 'bar', synchronizationId: 'x' }, null),
		).toEqual({ foo: 'bar' })
	})

	it('replaces a non-object arguments value with a fresh object', () => {
		for (const garbage of ['garbage', [], null, 7]) {
			expect(writeSynchronizationId(garbage, 'abc')).toEqual({
				synchronizationId: 'abc',
			})
		}
	})
})

describe('Jobs form configuration consistency', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.join(REPO_ROOT, 'src/manifest.json'), 'utf8'),
	)
	const jobsPage = manifest.pages.find((page) => page.id === 'Jobs')
	const config = jobsPage.config
	const overrides = config.fieldOverrides
	const fragment = JSON.parse(
		fs.readFileSync(
			path.join(REPO_ROOT, 'lib/Settings/register.d/job-form-fields.json'),
			'utf8',
		),
	)
	const fragmentProps = fragment.components.schemas.job.properties

	it('offers exactly the Action classes that exist in lib/Action', () => {
		// This is the check whose absence let FlowAction sit in lib/Action for a
		// release without ever appearing in the picker.
		const onDisk = fs
			.readdirSync(path.join(REPO_ROOT, 'lib/Action'))
			.filter((file) => file.endsWith('.php'))
			.map(
				(file) =>
					`OCA\\OpenConnector\\Action\\${file.replace(/\.php$/, '')}`,
			)
		expect([...overrides.jobClass.enum].sort()).toEqual([...onDisk].sort())
	})

	it('has a translated label for every offered class', () => {
		// The SFC is read as text because vitest runs without the Vue SFC
		// plugin. The label map lives there (not in the manifest) so the strings
		// stay extractable by tests/l10n/check-l10n.js; without this assertion an
		// unmapped class would silently render its raw FQN in the dropdown.
		const sfc = fs.readFileSync(
			path.join(REPO_ROOT, 'src/modals/v2/JobFormFields.vue'),
			'utf8',
		)
		for (const fqn of overrides.jobClass.enum) {
			// JSON gives single backslashes; the SFC writes them doubled as JS
			// string escapes. Compare source forms with a substring check rather
			// than a regex — escaping backslashes for a pattern twice over is how
			// this assertion silently passes on nothing.
			const asWrittenInSource = `'${fqn.replace(/\\/g, '\\\\')}': t(`
			expect(sfc.includes(asWrittenInSource), `no t() label for ${fqn}`).toBe(
				true,
			)
		}
	})

	it('keeps the synchronization class the form branches on inside the offered set', () => {
		// A rename that missed the manifest would silently kill the picker.
		expect(overrides.jobClass.enum).toContain(SYNCHRONIZATION_ACTION_CLASS)
	})

	it('only overrides fields it also includes', () => {
		for (const key of Object.keys(overrides)) {
			expect(
				config.includeFields,
				`${key} is overridden but not included`,
			).toContain(key)
		}
	})

	it('declares an order in the schema fragment for every included field', () => {
		// `arguments` is exempt: fieldsFromSchema drops bare `type: object`
		// properties before overrides apply, so it never reaches the form and
		// the Synchronization picker stands in for it.
		for (const key of config.includeFields.filter(
			(field) => field !== 'arguments',
		)) {
			expect(fragmentProps[key], `${key} has no schema order`).toBeDefined()
			expect(typeof fragmentProps[key].order).toBe('number')
		}
	})

	it('keeps field order unambiguous', () => {
		// Equal orders fall through to alphabetical, which is the behaviour this
		// whole change exists to remove.
		const orders = Object.values(fragmentProps).map((prop) => prop.order)
		expect(new Set(orders).size).toBe(orders.length)
	})

	it('puts the four scheduling flags in one contiguous group', () => {
		const flagKeys = Object.keys(overrides).filter(
			(key) => overrides[key].group === 'flags',
		)
		expect(flagKeys).toEqual([
			'timeSensitive',
			'allowParallelRuns',
			'isEnabled',
			'singleRun',
		])

		// Contiguous by ORDER, not merely present: groupFieldRuns coalesces only
		// consecutive fields, so a gap here would render two half-grids.
		const sorted = Object.keys(fragmentProps).sort(
			(a, b) => fragmentProps[a].order - fragmentProps[b].order,
		)
		const positions = flagKeys
			.map((key) => sorted.indexOf(key))
			.sort((a, b) => a - b)
		expect(positions[positions.length - 1] - positions[0]).toBe(
			flagKeys.length - 1,
		)
	})

	it('does not put an enum or an errorRetention default in the schema', () => {
		// Both are deliberate: a schema enum is enforced on save (breaking the
		// seeded Example*Job rows and third-party Action classes), and a schema
		// default for errorRetention would cut API-created jobs from 30-day to
		// 1-day error-log retention. Presentation and form prefill only.
		expect(fragmentProps.jobClass.enum).toBeUndefined()
		expect(fragmentProps.errorRetention.default).toBeUndefined()
		expect(overrides.errorRetention.default).toBe(86400)
	})
})
