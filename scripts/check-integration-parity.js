#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App-side integration parity gate (hydra gate-24, `integration-parity`).
 *
 * WHAT THIS CHECKS, AND WHY IT LIVES HERE
 * ---------------------------------------
 * A leaf has TWO faces (ADR-019 AD-11/AD-13, ADR-066 decisions 4 and 7):
 *
 *   * a SERVER face — either a `LeafDescriptor` contributed through
 *     `RegisterLeafProvidersEvent`, or an `IntegrationProvider` registered on
 *     OpenRegister's `IntegrationRegistry`. This face is what the
 *     `openregister.integrations.leaves` capability advertises.
 *   * a JS face — a `registerIntegration({ id, … })` call that mounts the
 *     render pair on `window.OCA.OpenRegister.integrations`.
 *
 * The two are correlated ONLY by a shared `id`. Nothing at runtime notices when
 * one half is missing or when the two halves disagree: a descriptor with no JS
 * registration advertises a tab that renders nothing (a PHANTOM render
 * surface), and a JS registration with no server face mounts a widget that no
 * capability consumer can discover (an ORPHAN registration). A renderMode
 * disagreement is worse still — the host hands a `mount` leaf an SFC slot, or
 * asks a `component` leaf for a `mount()` it does not have, and the surface is
 * blank with no error.
 *
 * The canonical Node check that ships in `@conduction/nextcloud-vue`
 * (`scripts/check-integration-parity.js`) validates THAT library's own built-in
 * descriptors, and its ADR-066 cross-reference over `process.cwd()` is
 * WARN-only and self-disables in a repo with no `new LeafDescriptor(` at all.
 * Neither property is usable as an app-side gate: the library's `scripts/` dir
 * is not published to npm, so in CI the resolution fails and the historic
 * wrapper exits 0 having checked nothing. This check is therefore SELF-CONTAINED
 * — it reads only this repo's own sources, needs no node_modules, and FAILS.
 *
 * RULES (all hard; any violation exits 1)
 * ---------------------------------------
 *   R1  render pair       a JS registration declaring its own id must ship a
 *                         COMPLETE render pair for its renderMode:
 *                         `mount` + `unmount` for renderMode 'mount',
 *                         `tab` + `widget` for 'component' (the default).
 *   R2  id correlation    every server-side render-surface leaf id has a JS
 *                         registration, and every JS registration id has a
 *                         server-side face (descriptor OR provider).
 *   R3  renderMode        for a shared id, both faces declare the same
 *                         renderMode.
 *   R4  metadata          for a shared id, every field BOTH faces declare
 *                         statically (label, icon, group, requiredApp,
 *                         referenceType, surfaces) agrees.
 *   R5  spread source     a registration that inherits its identity by
 *                         spreading a descriptor (`...fooIntegration`) must
 *                         import that symbol from `@conduction/nextcloud-vue`
 *                         — the owning package. A spread of a local or
 *                         undefined binding registers a leaf with no id.
 *   R6  offlineConfig     an `offlineConfig` naming schemas/properties must
 *                         name ones this repo actually declares in its
 *                         OpenRegister register/schema JSON under
 *                         `lib/Settings/**`.
 *
 * A rule with no subject matter in this repo reports "0 checked" by name, so
 * the difference between "verified" and "there was nothing to verify" is
 * visible in the output rather than inferred from silence.
 *
 * Exit codes:
 *   0 — every rule that had subject matter passed
 *   1 — at least one parity violation (each printed as a `✗` line)
 */

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd()

/**
 * Constants owned by OpenRegister's `LeafDescriptor`, which is not in this
 * repo's tree. Resolving them by name is the only way a static reader can
 * follow `renderMode: LeafDescriptor::RENDER_MODE_MOUNT`.
 *
 * @type {Object<string, string>}
 */
const FOREIGN_CONSTANTS = {
	'LeafDescriptor::RENDER_MODE_MOUNT': 'mount',
	'LeafDescriptor::RENDER_MODE_COMPONENT': 'component',
	'LeafDescriptor::KIND_RENDER_SURFACE': 'render-surface',
	'LeafDescriptor::KIND_DATA_PROVIDER': 'data-provider',
	'LeafDescriptor::KIND_AGENT_RUNNER': 'agent-runner',
}

/**
 * `offlineConfig` keys that name an OpenRegister schema slug, and those that
 * name a property on the planned-item schema. Mirrors
 * `DEFAULT_FIELD_INSPECTION_CONFIG` in `@conduction/nextcloud-vue`.
 *
 * @type {{schemas: string[], plannedFields: string[]}}
 */
const OFFLINE_CONFIG_CONTRACT = {
	schemas: ['plannedSchema', 'referenceSchema', 'resultSchema'],
	plannedFields: ['templateRefField', 'assigneeField', 'dateField', 'titleField'],
}

/**
 * Recursively collect files under `root` matching `test(filename)`.
 *
 * @param {string} root Directory to walk.
 * @param {(name: string) => boolean} test Filename predicate.
 * @param {number} [maxDepth] Maximum recursion depth.
 *
 * @return {string[]} Absolute paths of matching files.
 */
function collectFiles(root, test, maxDepth = 10) {
	const out = []
	if (fs.existsSync(root) === false) {
		return out
	}
	const skip = new Set([
		'node_modules',
		'vendor',
		'.git',
		'dist',
		'build',
		'js',
		'coverage',
	])
	const walk = (dir, depth) => {
		if (depth > maxDepth) {
			return
		}
		let entries
		try {
			entries = fs.readdirSync(dir, { withFileTypes: true })
		} catch (e) {
			return
		}
		for (const ent of entries) {
			if (ent.isDirectory() === true) {
				if (skip.has(ent.name) === false) {
					walk(path.join(dir, ent.name), depth + 1)
				}
			} else if (ent.isFile() === true && test(ent.name) === true) {
				out.push(path.join(dir, ent.name))
			}
		}
	}
	walk(root, 0)
	return out
}

/**
 * Extract the balanced text that follows `open` at `src[from]`, honouring
 * string literals and comments so a brace inside a string never unbalances it.
 *
 * @param {string} src Source text.
 * @param {number} from Index of the opening delimiter.
 *
 * @return {string} The text between the delimiters (exclusive), '' when unbalanced.
 */
function balanced(src, from) {
	const open = src[from]
	const close = { '(': ')', '{': '}', '[': ']' }[open]
	let depth = 0
	let quote = null
	for (let i = from; i < src.length; i++) {
		const c = src[i]
		const prev = src[i - 1]
		if (quote !== null) {
			if (c === quote && prev !== '\\') {
				quote = null
			}
			continue
		}
		if (c === "'" || c === '"' || c === '`') {
			quote = c
			continue
		}
		if (c === '/' && src[i + 1] === '/') {
			const nl = src.indexOf('\n', i)
			i = nl === -1 ? src.length : nl
			continue
		}
		if (c === '/' && src[i + 1] === '*') {
			const end = src.indexOf('*/', i)
			i = end === -1 ? src.length : end + 1
			continue
		}
		if (c === '#' && open === '(') {
			const nl = src.indexOf('\n', i)
			i = nl === -1 ? src.length : nl
			continue
		}
		if (c === open) {
			depth++
		} else if (c === close) {
			depth--
			if (depth === 0) {
				return src.slice(from + 1, i)
			}
		}
	}
	return ''
}

/**
 * Split an argument/member list on top-level commas.
 *
 * @param {string} body The text between the delimiters.
 *
 * @return {string[]} Trimmed top-level members.
 */
function splitTopLevel(body) {
	const parts = []
	let depth = 0
	let quote = null
	let current = ''
	for (let i = 0; i < body.length; i++) {
		const c = body[i]
		const prev = body[i - 1]
		if (quote !== null) {
			current += c
			if (c === quote && prev !== '\\') {
				quote = null
			}
			continue
		}
		if (c === "'" || c === '"' || c === '`') {
			quote = c
			current += c
			continue
		}
		if (c === '/' && body[i + 1] === '/') {
			const nl = body.indexOf('\n', i)
			i = nl === -1 ? body.length : nl
			continue
		}
		if (c === '/' && body[i + 1] === '*') {
			const end = body.indexOf('*/', i)
			i = end === -1 ? body.length : end + 1
			continue
		}
		if (c === '#') {
			const nl = body.indexOf('\n', i)
			i = nl === -1 ? body.length : nl
			continue
		}
		if (c === '(' || c === '[' || c === '{') {
			depth++
		} else if (c === ')' || c === ']' || c === '}') {
			depth--
		}
		if (c === ',' && depth === 0) {
			parts.push(current.trim())
			current = ''
			continue
		}
		current += c
	}
	if (current.trim() !== '') {
		parts.push(current.trim())
	}
	return parts
}

/**
 * Resolve a PHP expression to a string or array of strings, or null when it is
 * not statically knowable (which is never a failure — unknown values are simply
 * not compared).
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} localConsts Same-file const table.
 * @param {Object<string, (string|string[])>} globalConsts `Class::CONST` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolvePhp(expr, localConsts, globalConsts) {
	const e = expr.trim()
	let m = /^'((?:[^'\\]|\\.)*)'$/.exec(e) || /^"((?:[^"\\]|\\.)*)"$/.exec(e)
	if (m !== null) {
		return m[1].replace(/\\'/g, "'").replace(/\\"/g, '"')
	}
	// `$this->l10n->t('X')` / `$this->l->t('X', […])` — the translated literal.
	m =
		/^\$this->[A-Za-z0-9_]+->t\(\s*'((?:[^'\\]|\\.)*)'/.exec(e)
		|| /^\$this->[A-Za-z0-9_]+->t\(\s*"((?:[^"\\]|\\.)*)"/.exec(e)
	if (m !== null) {
		return m[1]
	}
	m = /^(?:self|static)::([A-Z0-9_]+)$/.exec(e)
	if (m !== null) {
		return Object.prototype.hasOwnProperty.call(localConsts, m[1])
			? localConsts[m[1]]
			: null
	}
	m = /^([A-Za-z_][A-Za-z0-9_]*)::([A-Z0-9_]+)$/.exec(e)
	if (m !== null) {
		const key = `${m[1]}::${m[2]}`
		if (Object.prototype.hasOwnProperty.call(FOREIGN_CONSTANTS, key) === true) {
			return FOREIGN_CONSTANTS[key]
		}
		return Object.prototype.hasOwnProperty.call(globalConsts, key)
			? globalConsts[key]
			: null
	}
	if (e.startsWith('[') === true) {
		const members = splitTopLevel(balanced(e, 0))
		const out = []
		for (const member of members) {
			const v = resolvePhp(member, localConsts, globalConsts)
			if (typeof v !== 'string') {
				return null
			}
			out.push(v)
		}
		return out
	}
	return null
}

/**
 * Build the same-file `const NAME = …` table for a PHP source.
 *
 * @param {string} src PHP source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function phpLocalConsts(src) {
	const table = {}
	const re = /\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*/g
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		let expr
		if (src[start] === '[') {
			expr = src.slice(start, start + balanced(src, start).length + 2)
		} else {
			const semi = src.indexOf(';', start)
			expr = semi === -1 ? '' : src.slice(start, semi)
		}
		const v = resolvePhp(expr, table, {})
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * Collect the server-side leaf faces declared in this repo's PHP.
 *
 * Two shapes count as a server face, because both are how a leaf reaches the
 * `openregister.integrations.leaves` capability:
 *   * `new LeafDescriptor(…)` — the ADR-066 collect-event contribution;
 *   * an `IntegrationProvider` (extends `AbstractIntegrationProvider` or
 *     implements `IntegrationProviderInterface`) whose `getId()` returns a
 *     literal — the `IntegrationRegistry::addProvider()` path.
 *
 * @return {Array<object>} The discovered server faces.
 */
function collectServerFaces() {
	const faces = []
	const phpFiles = collectFiles(path.join(REPO_ROOT, 'lib'), (n) =>
		n.endsWith('.php'),
	)

	// Global `Class::CONST` table, so `Application::APP_ID` resolves.
	const globalConsts = {}
	const sources = new Map()
	for (const file of phpFiles) {
		let src
		try {
			src = fs.readFileSync(file, 'utf8')
		} catch (e) {
			continue
		}
		sources.set(file, src)
		const cls =
			/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/.exec(src)
		if (cls === null) {
			continue
		}
		const locals = phpLocalConsts(src)
		for (const [name, value] of Object.entries(locals)) {
			globalConsts[`${cls[1]}::${name}`] = value
		}
	}

	for (const [file, src] of sources) {
		const rel = path.relative(REPO_ROOT, file)
		const locals = phpLocalConsts(src)

		// --- shape 1: `new LeafDescriptor(` named arguments -------------------
		let idx = 0
		while ((idx = src.indexOf('new LeafDescriptor(', idx)) !== -1) {
			const open = src.indexOf('(', idx)
			const args = splitTopLevel(balanced(src, open))
			const face = { kind: 'LeafDescriptor', file: rel, fields: {} }
			for (const arg of args) {
				const m = /^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([\s\S]+)$/.exec(arg)
				if (m === null) {
					continue
				}
				const value = resolvePhp(m[2], locals, globalConsts)
				if (value !== null) {
					face.fields[m[1]] = value
				}
			}
			face.id = typeof face.fields.id === 'string' ? face.fields.id : null
			const kinds = Array.isArray(face.fields.kinds) ? face.fields.kinds : []
			face.renderSurface = kinds.includes('render-surface')
			face.renderMode =
				typeof face.fields.renderMode === 'string'
					? face.fields.renderMode
					: null
			faces.push(face)
			idx = open + 1
		}

		// --- shape 2: an IntegrationProvider ---------------------------------
		const isProvider =
			/extends\s+AbstractIntegrationProvider\b/.test(src)
			|| /implements\s+[^{]*\bIntegrationProviderInterface\b/.test(src)
		if (isProvider === false) {
			continue
		}
		const getters = {
			getId: 'id',
			getLabel: 'label',
			getIcon: 'icon',
			getGroup: 'group',
			getRequiredApp: 'requiredApp',
			getReferenceType: 'referenceType',
		}
		const face = { kind: 'IntegrationProvider', file: rel, fields: {} }
		for (const [method, field] of Object.entries(getters)) {
			const sig = new RegExp(
				`function\\s+${method}\\s*\\([^)]*\\)[^{;]*\\{`,
			).exec(src)
			if (sig === null) {
				continue
			}
			const body = balanced(src, sig.index + sig[0].length - 1)
			const ret = /\breturn\s+([^;]+);/.exec(body)
			if (ret === null) {
				continue
			}
			const value = resolvePhp(ret[1], locals, globalConsts)
			if (value !== null) {
				face.fields[field] = value
			}
		}
		if (typeof face.fields.id === 'string') {
			face.id = face.fields.id
			// A provider is a data face, not a render surface: it is discoverable
			// through the capability and therefore MUST have a JS registration,
			// but it does not itself declare a render pair.
			face.renderSurface = false
			face.renderMode =
				typeof face.fields.renderMode === 'string'
					? face.fields.renderMode
					: null
			faces.push(face)
		}
	}
	return faces
}

/**
 * Resolve a JS expression to a string or array of strings, or null when it is
 * not statically knowable.
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolveJs(expr, locals) {
	const e = expr.trim()
	let m =
		/^'((?:[^'\\]|\\.)*)'$/.exec(e)
		|| /^"((?:[^"\\]|\\.)*)"$/.exec(e)
		|| /^`([^`$]*)`$/.exec(e)
	if (m !== null) {
		return m[1].replace(/\\'/g, "'").replace(/\\"/g, '"')
	}
	// `t('appid', 'Label')` / `t('Label')` — the translated literal.
	m =
		/^t\(\s*'[^']*'\s*,\s*'((?:[^'\\]|\\.)*)'/.exec(e)
		|| /^t\(\s*'((?:[^'\\]|\\.)*)'\s*\)/.exec(e)
	if (m !== null) {
		return m[1]
	}
	if (e.startsWith('[') === true) {
		const out = []
		for (const member of splitTopLevel(balanced(e, 0))) {
			const v = resolveJs(member, locals)
			if (typeof v !== 'string') {
				return null
			}
			out.push(v)
		}
		return out
	}
	if (
		/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(e) === true
		&& Object.prototype.hasOwnProperty.call(locals, e) === true
	) {
		return locals[e]
	}
	return null
}

/**
 * Build the same-file `const NAME = { … }` table, keyed to the OBJECT LITERAL
 * TEXT rather than a resolved scalar.
 *
 * `jsLocalConsts` below resolves constants to VALUES, and for a `{` it only
 * reads to end-of-line, so a multi-line descriptor object never lands in its
 * table. That is fine for `id: SOME_CONST`, but it cannot serve a registration
 * whose whole descriptor is passed by name:
 *
 *   export const decisionsLeafDescriptor = { id: …, renderMode: 'mount', … }
 *   …
 *   OCA.OpenRegister.integrations.register(decisionsLeafDescriptor)
 *
 * The member parser in `collectJsRegistrations` needs the literal's TEXT, so
 * this table keeps the balanced `{ … }` body verbatim for that one purpose.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, string>} Constant name to object-literal inner text.
 */
function jsLocalObjectLiterals(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\{/g
	let m
	while ((m = re.exec(src)) !== null) {
		const brace = m.index + m[0].length - 1
		table[m[1]] = balanced(src, brace)
	}
	return table
}

/**
 * Build the same-file `const NAME = …` table for a JS/Vue source.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function jsLocalConsts(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/g
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		let expr
		if (src[start] === '[') {
			expr = src.slice(start, start + balanced(src, start).length + 2)
		} else {
			const nl = src.indexOf('\n', start)
			expr =
				nl === -1
					? src.slice(start)
					: src.slice(start, nl).replace(/,\s*$/, '')
		}
		const v = resolveJs(expr, table)
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * Collect the JS `registerIntegration({ … })` call sites in `src/**`.
 *
 * @return {Array<object>} The discovered registrations.
 */
function collectJsRegistrations() {
	const regs = []
	const jsFiles = collectFiles(
		path.join(REPO_ROOT, 'src'),
		(n) => n.endsWith('.js') || n.endsWith('.ts') || n.endsWith('.vue'),
	)
	for (const file of jsFiles) {
		let src
		try {
			src = fs.readFileSync(file, 'utf8')
		} catch (e) {
			continue
		}
		// BOTH SUPPORTED REGISTRATION APIs, NOT ONE.
		//
		// `registerIntegration(descriptor)` is the convenience wrapper exported
		// from @conduction/nextcloud-vue. The registry object it wraps is
		// equally canonical and is called directly as
		// `OCA.OpenRegister.integrations.register(descriptor)` — including by
		// nextcloud-vue's OWN built-in leaves. Matching only the wrapper made
		// this checker report ZERO registrations for a repo that plainly has
		// one, which the wrapper script then correctly refused to call a pass.
		// gate-24's own selector probe was fixed for exactly this and reads
		// both forms; this checker was a generation behind it.
		if (
			src.includes('registerIntegration') === false
			&& src.includes('integrations.register') === false
		) {
			continue
		}
		const rel = path.relative(REPO_ROOT, file)
		const locals = jsLocalConsts(src)
		const objects = jsLocalObjectLiterals(src)
		// `\s*\(` anchors both alternatives to a CALL, so neither
		// `registerIntegrationIcons(` nor `installIntegrationRegistry(` matches.
		const re = /(?:\bregisterIntegration|\bintegrations\s*\.\s*register)\s*\(/g
		let m
		while ((m = re.exec(src)) !== null) {
			const before = src.slice(Math.max(0, m.index - 20), m.index)
			if (/function\s+$/.test(before) === true) {
				continue // the library's own `export function registerIntegration(`
			}
			const open = src.indexOf('(', m.index)
			let argText = balanced(src, open).trim()
			if (argText.startsWith('{') === false) {
				// A descriptor passed BY NAME is the same registration as an
				// inline literal; resolve it to the literal's text or skip.
				const ident = /^([A-Za-z_$][A-Za-z0-9_$]*)$/.exec(argText)
				if (ident === null || objects[ident[1]] === undefined) {
					continue
				}
				argText = '{' + objects[ident[1]] + '}'
			}
			const reg = { file: rel, fields: {}, keys: [], spreads: [] }
			for (const member of splitTopLevel(balanced(argText, 0))) {
				const spread = /^\.\.\.\s*([A-Za-z_$][A-Za-z0-9_$]*)/.exec(member)
				if (spread !== null) {
					reg.spreads.push(spread[1])
					continue
				}
				const kv =
					/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*(?::\s*([\s\S]+))?$/.exec(
						member,
					)
				if (kv === null) {
					continue
				}
				const key = kv[1] || kv[2] || kv[3]
				reg.keys.push(key)
				// A shorthand (`mount,`) carries the key without a value.
				const value = kv[4] === undefined ? null : resolveJs(kv[4], locals)
				if (value !== null) {
					reg.fields[key] = value
				}
				if (
					key === 'offlineConfig'
					&& kv[4] !== undefined
					&& kv[4].trim().startsWith('{') === true
				) {
					reg.offlineConfig = {}
					for (const cfg of splitTopLevel(balanced(kv[4].trim(), 0))) {
						const ckv =
							/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*:\s*([\s\S]+)$/.exec(
								cfg,
							)
						if (ckv === null) {
							continue
						}
						const cvalue = resolveJs(ckv[4], locals)
						if (typeof cvalue === 'string') {
							reg.offlineConfig[ckv[1] || ckv[2] || ckv[3]] = cvalue
						}
					}
				}
			}
			reg.id = typeof reg.fields.id === 'string' ? reg.fields.id : null
			reg.renderMode =
				typeof reg.fields.renderMode === 'string'
					? reg.fields.renderMode
					: null
			// Import source per spread symbol, for R5.
			reg.spreadSources = {}
			for (const symbol of reg.spreads) {
				reg.spreadSources[symbol] = importSourceOf(src, symbol)
			}
			regs.push(reg)
		}
	}
	return regs
}

/**
 * Find the module a symbol is imported from.
 *
 * @param {string} src JS source text.
 * @param {string} symbol The imported binding name.
 *
 * @return {string|null} The module specifier, or null when not imported.
 */
function importSourceOf(src, symbol) {
	const re = /import\s+([\s\S]*?)\s+from\s+'([^']+)'/g
	let m
	while ((m = re.exec(src)) !== null) {
		const clause = m[1]
		const named =
			clause.indexOf('{') === -1 ? '' : balanced(clause, clause.indexOf('{'))
		const bindings = splitTopLevel(named).map((b) =>
			b
				.split(/\s+as\s+/)
				.pop()
				.trim(),
		)
		const defaultBinding = clause
			.replace(/\{[\s\S]*\}/, '')
			.replace(/,/g, '')
			.trim()
		if (bindings.includes(symbol) === true || defaultBinding === symbol) {
			return m[2]
		}
	}
	return null
}

/**
 * Collect the schema slugs and their property names from this repo's
 * OpenRegister register/schema declarations under `lib/Settings/**`.
 *
 * @return {{schemas: Object<string, string[]>, files: number}} Slug to property
 *   names, and how many declaration files were read.
 */
function collectOrSchemas() {
	const schemas = {}
	let files = 0
	for (const file of collectFiles(path.join(REPO_ROOT, 'lib', 'Settings'), (n) =>
		n.endsWith('.json'),
	)) {
		let doc
		try {
			doc = JSON.parse(fs.readFileSync(file, 'utf8'))
		} catch (e) {
			continue
		}
		const declared = doc && doc.components && doc.components.schemas
		if (
			declared === undefined
			|| declared === null
			|| typeof declared !== 'object'
		) {
			continue
		}
		files++
		for (const [key, schema] of Object.entries(declared)) {
			const slug =
				schema && typeof schema.slug === 'string' ? schema.slug : key
			const props =
				schema && schema.properties && typeof schema.properties === 'object'
					? Object.keys(schema.properties)
					: []
			schemas[slug] = (schemas[slug] || []).concat(props)
		}
	}
	return { schemas, files }
}

/**
 * Compare two statically-resolved values for parity.
 *
 * @param {string|string[]} a Left value.
 * @param {string|string[]} b Right value.
 *
 * @return {boolean} True when equal (arrays compare as sets).
 */
function sameValue(a, b) {
	if (Array.isArray(a) === true && Array.isArray(b) === true) {
		const left = [...a].sort()
		const right = [...b].sort()
		return left.length === right.length && left.every((v, i) => v === right[i])
	}
	return a === b
}

/**
 * Run every rule and report.
 *
 * @return {void}
 */
function main() {
	const failures = []
	const counts = {}
	const faces = collectServerFaces()
	const regs = collectJsRegistrations()
	const { schemas, files: schemaFiles } = collectOrSchemas()

	const jsById = new Map()
	for (const r of regs) {
		if (r.id !== null) {
			jsById.set(r.id, r)
		}
	}
	const faceById = new Map()
	for (const f of faces) {
		if (f.id !== null) {
			faceById.set(f.id, f)
		}
	}

	// --- R1: complete render pair for the declared renderMode ---------------
	counts.R1 = 0
	for (const r of regs) {
		if (r.id === null) {
			continue // identity inherited from a spread — covered by R5
		}
		counts.R1++
		const mode = r.renderMode === null ? 'component' : r.renderMode
		const required = mode === 'mount' ? ['mount', 'unmount'] : ['tab', 'widget']
		for (const key of required) {
			if (r.keys.includes(key) === false) {
				failures.push(
					`✗ [R1 render-pair] registerIntegration id "${r.id}" (${r.file}) declares `
						+ `renderMode "${mode}" but is missing the required \`${key}\` key — an `
						+ `incomplete render pair renders nothing on its surface (ADR-019 AD-11/AD-13, `
						+ `ADR-066 decision 7).`,
				)
			}
		}
	}

	// --- R2: server↔JS id correlation ---------------------------------------
	counts.R2 = 0
	for (const f of faces) {
		if (f.id === null) {
			failures.push(
				`✗ [R2 id-correlation] a ${f.kind} in ${f.file} declares no statically-readable `
					+ `\`id\` — a leaf whose id cannot be read cannot be correlated with its JS half.`,
			)
			continue
		}
		counts.R2++
		if (jsById.has(f.id) === false) {
			failures.push(
				`✗ [R2 id-correlation] server leaf "${f.id}" (${f.kind}, ${f.file}) has NO matching `
					+ `registerIntegration({ id: '${f.id}' }) in src/** — phantom leaf: the `
					+ `openregister.integrations.leaves capability advertises a surface that never mounts.`,
			)
		}
	}
	for (const r of regs) {
		if (r.id === null) {
			continue
		}
		counts.R2++
		if (faceById.has(r.id) === false) {
			failures.push(
				`✗ [R2 id-correlation] registerIntegration id "${r.id}" (${r.file}) has NO matching `
					+ `server-side face in lib/** (neither a \`new LeafDescriptor(id: '${r.id}')\` nor an `
					+ `IntegrationProvider whose getId() returns '${r.id}') — orphan registration: it `
					+ `mounts on window.OCA.OpenRegister.integrations but is invisible to the `
					+ `openregister.integrations.leaves capability.`,
			)
		}
	}

	// --- R3: renderMode agreement across layers -----------------------------
	counts.R3 = 0
	for (const [id, f] of faceById) {
		const r = jsById.get(id)
		if (r === undefined || f.renderMode === null || r.renderMode === null) {
			continue
		}
		counts.R3++
		if (f.renderMode !== r.renderMode) {
			failures.push(
				`✗ [R3 renderMode] leaf "${id}" declares renderMode "${f.renderMode}" server-side `
					+ `(${f.file}) but "${r.renderMode}" in its JS registration (${r.file}) — a `
					+ `renderMode mismatch blanks the surface (ADR-066 decision 7).`,
			)
		}
	}

	// --- R4: cross-layer metadata agreement ---------------------------------
	const COMPARED = [
		'label',
		'icon',
		'group',
		'requiredApp',
		'referenceType',
		'surfaces',
	]
	counts.R4 = 0
	for (const [id, f] of faceById) {
		const r = jsById.get(id)
		if (r === undefined) {
			continue
		}
		for (const field of COMPARED) {
			const left = f.fields[field]
			const right = r.fields[field]
			if (left === undefined || right === undefined) {
				continue
			}
			counts.R4++
			if (sameValue(left, right) === false) {
				failures.push(
					`✗ [R4 metadata] leaf "${id}" field \`${field}\` mismatch across layers: `
						+ `server (${f.file}) says ${JSON.stringify(left)}, JS (${r.file}) says `
						+ `${JSON.stringify(right)} — the two halves describe different leaves.`,
				)
			}
		}
	}

	// --- R5: a spread registration must spread the OWNING package's descriptor
	counts.R5 = 0
	for (const r of regs) {
		for (const symbol of r.spreads) {
			counts.R5++
			const source = r.spreadSources[symbol]
			if (source === null) {
				failures.push(
					`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\`, `
						+ `which is not imported in that file — the spread of an undefined binding `
						+ `registers a leaf with no id.`,
				)
			} else if (source !== '@conduction/nextcloud-vue') {
				failures.push(
					`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\` `
						+ `imported from "${source}", not from the leaf-owning package `
						+ `@conduction/nextcloud-vue — an override must extend the descriptor it `
						+ `overrides, or the id it registers under is not the one it thinks.`,
				)
			}
		}
		if (r.id === null && r.spreads.length === 0) {
			failures.push(
				`✗ [R5 spread-source] registerIntegration in ${r.file} declares neither an \`id\` `
					+ `nor a spread of a descriptor — it registers a leaf with no identity.`,
			)
		}
	}

	// --- R6: offlineConfig names schemas/properties this repo declares -------
	counts.R6 = 0
	for (const r of regs) {
		if (r.offlineConfig === undefined) {
			continue
		}
		if (schemaFiles === 0) {
			failures.push(
				`✗ [R6 offlineConfig] registerIntegration in ${r.file} supplies an \`offlineConfig\` `
					+ `naming OpenRegister schemas, but this repo declares no register/schema JSON under `
					+ `lib/Settings/** to check it against — the mapping cannot be verified.`,
			)
			continue
		}
		const planned = r.offlineConfig.plannedSchema
		for (const key of OFFLINE_CONFIG_CONTRACT.schemas) {
			const slug = r.offlineConfig[key]
			if (slug === undefined) {
				continue
			}
			counts.R6++
			if (Object.prototype.hasOwnProperty.call(schemas, slug) === false) {
				failures.push(
					`✗ [R6 offlineConfig] leaf offlineConfig.${key} = "${slug}" (${r.file}) names a `
						+ `schema this repo does not declare in lib/Settings/** — the leaf would query a `
						+ `schema that does not exist. Declared slugs: ${Object.keys(schemas).sort().join(', ')}`,
				)
			}
		}
		for (const key of OFFLINE_CONFIG_CONTRACT.plannedFields) {
			const prop = r.offlineConfig[key]
			if (prop === undefined || planned === undefined) {
				continue
			}
			const props = schemas[planned]
			if (props === undefined) {
				continue // already reported by the schema loop above
			}
			counts.R6++
			if (props.includes(prop) === false) {
				failures.push(
					`✗ [R6 offlineConfig] leaf offlineConfig.${key} = "${prop}" (${r.file}) is not a `
						+ `property of schema "${planned}" as declared in lib/Settings/** — the leaf `
						+ `would filter/display on a property that does not exist. Declared properties: `
						+ `${[...new Set(props)].sort().join(', ')}`,
				)
			}
		}
	}

	// --- report --------------------------------------------------------------
	const scope =
		`${faces.length} server leaf face(s), ${regs.length} JS registration(s), `
		+ `${schemaFiles} OpenRegister schema declaration file(s)`
	const perRule = Object.entries(counts)
		.map(([rule, n]) => `${rule}:${n}`)
		.join(' ')
	if (failures.length === 0) {
		// eslint-disable-next-line no-console
		console.log(
			`✓ integration parity: ${scope} — all rules pass (assertions run per rule: ${perRule})`,
		)
		if (Object.values(counts).every((n) => n === 0) === true) {
			// eslint-disable-next-line no-console
			console.error(
				'✗ integration parity: every rule had ZERO subject matter, yet gate-24 selected this '
					+ 'repo as one that registers leaves. That contradiction means this checker failed to '
					+ 'read what the gate can see — a pass here would be a pass over nothing.',
			)
			process.exit(1)
		}
		process.exit(0)
	}
	// The header carries no `✗` on purpose: gate-24 counts violations by
	// grepping `^✗` in this log, so every violation — and only a violation —
	// starts a line with it.
	// eslint-disable-next-line no-console
	console.error(
		`integration parity gate FAILED — ${failures.length} violation(s) over ${scope}:`,
	)
	for (const f of failures) {
		// eslint-disable-next-line no-console
		console.error(f)
	}
	// eslint-disable-next-line no-console
	console.error(`\nAssertions run per rule: ${perRule}`)
	process.exit(1)
}

if (require.main === module) {
	main()
}

module.exports = {
	collectServerFaces,
	collectJsRegistrations,
	collectOrSchemas,
}
