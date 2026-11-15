/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden of the CSS each rule action form applies to its own template. The
 * snapshot was recorded from the per-form style blocks; it must stay equal
 * when the forms share a stylesheet.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */

import { readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { effectiveStyles } from './sfc/effectiveStyles.js'

const DIR = join(import.meta.dirname, '../../src/views/Rule/actionForms')
const FORMS = readdirSync(DIR)
	.filter((name) => name.endsWith('.vue'))
	.sort()

describe('action form styles', () => {
	it('covers every action form', () => {
		expect(FORMS).toMatchSnapshot()
	})

	for (const form of FORMS) {
		it(`${form} applies the same rules`, () => {
			expect(effectiveStyles(join(DIR, form))).toMatchSnapshot()
		})
	}
})
