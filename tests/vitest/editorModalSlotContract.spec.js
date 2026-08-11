/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bespoke editor modals are mounted ONLY as a `form-dialog` slot
 * replacement on `CnIndexPage`, declared in `src/manifest.json`:
 *
 *     "slots": { "form-dialog": "SynchronizationEditorModal" }
 *
 * `CnPageRenderer` mounts a manifest slot as
 * `<component :is=… v-bind="slotProps" />`, which binds PROPS ONLY. So the
 * whole contract between these modals and their host is the slot's scope, and
 * a name that is not in that scope arrives as `undefined` — silently, with no
 * Vue warning, exactly like a `<template #wrong-name>` that matches nothing.
 *
 * openconnector#1150 shipped three editors whose `canSave` required
 * `typeof this.confirm === 'function'` while `CnIndexPage`'s slot bound only
 * `show` / `item` / `schema` / `close`. `confirm` was never provided, so
 * `canSave` could never become true: all three rendered, accepted input, and
 * could not save. Playwright reported it as
 * "Create button must be enabled in form dialog"; nothing else did.
 *
 * These tests pin the contract from THIS side. They exercise the real
 * `canSave` from the real `.vue` file against the two scopes — the one that
 * shipped, and the one ConductionNL/nextcloud-vue#614 provides — so the
 * failure is attributable here, in a unit test, instead of surfacing as a
 * disabled button in a browser twenty minutes into CI.
 */

import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { describe, it, expect } from 'vitest'

const here = path.dirname(fileURLToPath(import.meta.url))

/**
 * Load an SFC's `export default {…}` options object without a bundler.
 *
 * The repo's vitest environment is `node` with no SFC transform, so the
 * component cannot be imported. Every `import` is replaced with a stub of the
 * same shape: the options object references imported identifiers only in
 * `components:` (never in the logic under test), so a stub is indistinguishable
 * from the real binding for these assertions — and using the real file is the
 * point. A copied-out `canSave` would pass forever after someone edited the
 * component.
 *
 * @param {string} relPath Repo-relative path to the `.vue` file.
 * @return {object} The component options object.
 */
function loadSfcOptions(relPath) {
	const source = fs.readFileSync(path.resolve(here, '../..', relPath), 'utf8')

	const scriptMatch = source.match(/<script[^>]*>([\s\S]*?)<\/script>/)
	if (!scriptMatch) throw new Error(`no <script> block in ${relPath}`)
	let script = scriptMatch[1]

	// Collect every identifier an import statement binds, then drop the
	// statements themselves — a bare specifier cannot be resolved here.
	const bound = new Set()
	const importRe = /import\s+([\s\S]*?)\s+from\s+['"][^'"]+['"]\s*;?/g
	let m
	while ((m = importRe.exec(script)) !== null) {
		const clause = m[1].trim()
		const braced = clause.match(/\{([\s\S]*?)\}/)
		if (braced) {
			for (const part of braced[1].split(',')) {
				const name = part.split(/\s+as\s+/).pop().trim()
				if (name) bound.add(name)
			}
		}
		const defaultName = clause.replace(/\{[\s\S]*?\}/, '').replace(/,/g, '').trim()
		if (defaultName && /^[A-Za-z_$][\w$]*$/.test(defaultName)) bound.add(defaultName)
	}
	script = script.replace(importRe, '')
	// Side-effect-only imports (`import 'x.css'`).
	script = script.replace(/import\s+['"][^'"]+['"]\s*;?/g, '')

	const stubs = [...bound].map((name) => `const ${name} = function () {};`).join('\n')
	const body = `${stubs}\n${script.replace('export default', 'return')}`

	// eslint-disable-next-line no-new-func
	return new Function(body)()
}

/**
 * Each modal with the MINIMAL draft its own `canSave` accepts.
 *
 * The drafts differ on purpose. A uniform `{ name }` fixture reported
 * `RuleEditorModal` as still-broken under #614 — it is not: a rule genuinely
 * requires `action` and `type` as well, and the fixture, not the component,
 * was wrong. Spelling each modal's real requirement out here keeps the
 * confirm-binding assertion about the confirm binding, instead of quietly
 * measuring whichever required field the fixture forgot.
 */
const MODALS = [
	{
		name: 'SynchronizationEditorModal',
		file: 'src/modals/v2/SynchronizationEditorModal.vue',
		validDraft: { name: 'a name' },
		extraVm: {},
	},
	{
		name: 'MappingEditorModal',
		file: 'src/modals/v2/MappingEditorModal.vue',
		validDraft: { name: 'a name' },
		extraVm: {},
	},
	{
		name: 'RuleEditorModal',
		file: 'src/modals/v2/RuleEditorModal.vue',
		validDraft: { name: 'a name', action: 'create', type: 'mapping' },
		extraVm: { rawConditionsError: '' },
	},
	{
		name: 'ConsumerEditorModal',
		file: 'src/modals/v2/ConsumerEditorModal.vue',
		validDraft: { name: 'a name' },
		extraVm: { authConfigError: '' },
	},
]

/**
 * The scope `CnIndexPage` binds on its `form-dialog` slot AFTER
 * ConductionNL/nextcloud-vue#614. `confirm` is bound as a prop precisely so it
 * survives `v-bind` from a manifest-declared replacement.
 */
const SLOT_SCOPE_WITH_CONFIRM = ['show', 'item', 'schema', 'confirm', 'close']

/**
 * The scope that shipped in `@conduction/nextcloud-vue@2.2.0-vue3.3` — the
 * version this app pins — and still in `2.2.0-vue3.6`. No `confirm`.
 */
const SLOT_SCOPE_AS_SHIPPED = ['show', 'item', 'schema', 'close']

/**
 * Read the bindings `CnIndexPage` puts on one of its named slots, from the
 * `@conduction/nextcloud-vue` actually installed — not from a copy of the
 * contract written down here, which would agree with itself forever.
 *
 * @param {string} slotName The slot's `name` attribute.
 * @return {string[]} The bound scope keys (`:foo="bar"` → `foo`).
 */
function installedSlotBindings(slotName) {
	const file = path.resolve(
		here,
		'../../node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/CnIndexPage.vue',
	)
	const source = fs.readFileSync(file, 'utf8')
	// The <slot> tag spans several lines and `name` is not the first attribute,
	// so match the whole tag and read its bindings out of it. A single-line
	// regex silently finds nothing here and would report an empty scope.
	const tag = source.match(new RegExp(`<slot\\b[^>]*?name="${slotName}"[^>]*?>`, 's'))
	if (!tag) throw new Error(`no <slot name="${slotName}"> in the installed CnIndexPage`)
	return [...tag[0].matchAll(/:([a-zA-Z][\w-]*)\s*=/g)].map((m) => m[1])
}

describe('the installed nextcloud-vue must bind the save path this app depends on', () => {
	it('binds confirm on the form-dialog slot (ConductionNL/nextcloud-vue#614)', () => {
		const bound = installedSlotBindings('form-dialog')

		// Guard the reader itself: a regex that matched nothing would make the
		// assertion below fail for the wrong reason.
		expect(bound, 'the slot-binding reader must find the known bindings').toEqual(
			expect.arrayContaining(['show', 'item', 'schema', 'close']),
		)

		expect(
			bound,
			'CnIndexPage must bind `confirm` on its form-dialog slot. Without it the three '
			+ 'manifest-declared editor modals (Synchronization/Mapping/Rule) render, accept '
			+ 'input and can never save — CnPageRenderer mounts a manifest slot with '
			+ 'v-bind="slotProps", so an @confirm listener on the default child is unreachable. '
			+ 'Fixed by ConductionNL/nextcloud-vue#614; this fails until a release carrying it '
			+ 'is pinned in package.json.',
		).toContain('confirm')
	})
})

describe('form-dialog slot contract for the bespoke editor modals', () => {
	for (const { name, file: relPath, validDraft, extraVm } of MODALS) {
		describe(name, () => {
			const options = loadSfcOptions(relPath)

			/**
			 * A view-model in the state a user reaches after filling the form.
			 *
			 * @param {object} overrides Fields to replace.
			 * @return {object} The mock `this` for a computed.
			 */
			const vmWith = (overrides = {}) => ({
				saving: false,
				nameError: '',
				item: null,
				...extraVm,
				draft: { ...validDraft },
				...overrides,
			})

			it('declares only props the host slot actually binds', () => {
				const declared = Object.keys(options.props || {})
				expect(declared.length, `${name} must declare props`).toBeGreaterThan(0)

				const unbindable = declared.filter((p) => !SLOT_SCOPE_WITH_CONFIRM.includes(p))
				expect(
					unbindable,
					`${name} declares ${JSON.stringify(unbindable)}, which CnIndexPage's `
					+ 'form-dialog slot does not bind. A manifest-declared replacement is mounted '
					+ 'with v-bind="slotProps", so an unbound prop is silently undefined.',
				).toEqual([])
			})

			it('cannot save when the host omits confirm — the openconnector#1150 defect', () => {
				// Everything else valid, so `confirm` is the ONLY thing missing.
				expect(
					options.computed.canSave.call(vmWith({ confirm: undefined })),
					`${name}.canSave must be false without a confirm binding — this is the state `
					+ 'that shipped, and it is why the Create button carried disabled="".',
				).toBe(false)
				expect(SLOT_SCOPE_AS_SHIPPED).not.toContain('confirm')
			})

			it('can save once the host binds confirm — nextcloud-vue#614', () => {
				expect(
					options.computed.canSave.call(vmWith({ confirm: () => {} })),
					`${name}.canSave must become true once the host binds confirm. If this fails, `
					+ '#614 does not resolve this editor and the modal has a second unmet dependency.',
				).toBe(true)
			})

			it('still refuses to save an unnamed record', () => {
				expect(
					options.computed.canSave.call(
						vmWith({ confirm: () => {}, draft: { ...validDraft, name: '' } }),
					),
					`${name} must not save a record with no name even when the host is wired`,
				).toBe(false)
			})

			it('still refuses to save while a save is already in flight', () => {
				expect(
					options.computed.canSave.call(vmWith({ confirm: () => {}, saving: true })),
					`${name} must not allow a second concurrent save`,
				).toBe(false)
			})
		})
	}
})
