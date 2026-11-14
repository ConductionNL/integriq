/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/* eslint-disable jsdoc/require-param */
/**
 * Shared l10n helpers.
 *
 * Operates on l10n/*.js (frontend translation files). Backend .json files are
 * a separate concern and are not handled here.
 *
 * VENDORED from openregister/scripts/lib/l10n.js — the two apps ship separate
 * npm packages, so there is no import path between them. Keep the two copies in
 * sync when either changes; the only intended divergence is DYNAMIC_KEYS below,
 * which is app-specific data.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')

/**
 * Load a single l10n/*.js file and return its app name, translations object,
 * and plural-form string. Throws if the file does not call OC.L10N.register.
 */
function loadJsTranslations(file) {
	const code = fs.readFileSync(file, 'utf8')
	let captured = null
	let plural = null
	let app = null
	const sandbox = {
		OC: {
			L10N: {
				register: (registeredApp, translations, pluralForm) => {
					app = registeredApp
					captured = translations
					plural = pluralForm
				},
			},
		},
	}
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: file })
	if (!captured || typeof captured !== 'object') {
		throw new Error(`OC.L10N.register was not called with a translations object in ${file}`)
	}
	if (!app) {
		throw new Error(`OC.L10N.register was not called with an app name in ${file}`)
	}
	return {
		app,
		translations: captured,
		pluralForm: plural || 'nplurals=2; plural=(n != 1);',
	}
}

/**
 * Case-insensitive alphabetical order so "apple" sorts next to "Apple" instead
 * of after "Zebra" the way a raw code-unit sort puts it, tie-broken by code
 * unit so the result is stable. Deliberately NOT localeCompare: that varies
 * with the Node/ICU version, which would make the sort order — and therefore
 * every locale file's diff — depend on who ran the tool.
 */
function compareKeys(a, b) {
	const x = a.toLowerCase()
	const y = b.toLowerCase()
	if (x < y) return -1
	if (x > y) return 1
	return a < b ? -1 : a > b ? 1 : 0
}

/**
 * Serialize an l10n/*.js file byte-for-byte in the layout the shipped files
 * already use, so a one-key edit produces a one-line diff.
 *
 * That layout is the Nextcloud/Transifex one, and it differs from plain
 * JSON.stringify in four ways that all matter for diff noise:
 *   - four-space indent (NOT tabs) for the app id, the brace and every entry,
 *     with entries at the SAME depth as the opening brace;
 *   - a space before the colon:  "key" : "value"
 *   - no trailing comma after the final entry;
 *   - a `);` terminator rather than `)`.
 *
 * Getting any of these wrong rewrites every line of all 37 locale files on the
 * next write. The previous implementation emitted tabs, `"key": "value"`, a
 * trailing comma and `)`, and leaned on `eslint --fix` to renormalize — which
 * silently no-ops when node_modules is absent, and never covered l10n/ anyway.
 *
 * Pluralized values (arrays) are emitted as compact JSON arrays, as on disk.
 *
 * Keys are sorted with compareKeys (case-insensitive CODE-UNIT order), which is
 * the order 36 of the 37 shipped locale files are actually in; localeCompare
 * matches none of them, because the two disagree on where punctuation sorts.
 * The lone exception is l10n/en.js, still in the order the original extraction
 * tool emitted, so the first write that touches en.js will re-sort it once.
 */
function serializeJs({ app, translations, pluralForm }) {
	const keys = Object.keys(translations).sort(compareKeys)
	const lines = keys.map((k, i) => {
		const value = translations[k]
		const comma = i === keys.length - 1 ? '' : ','
		return `    ${JSON.stringify(k)} : ${JSON.stringify(value)}${comma}`
	})
	return `OC.L10N.register(\n    ${JSON.stringify(app)},\n    {\n${lines.join('\n')}\n},\n${JSON.stringify(pluralForm)}\n);\n`
}

/**
 * Recursively walk a directory, collecting files whose extension is in `exts`.
 * Skips node_modules and dotfile directories.
 */
function walk(dir, exts, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue
			walk(full, exts, out)
		} else if (exts.includes(path.extname(entry.name))) {
			out.push(full)
		}
	}
	return out
}

/**
 * Matches the opening of a translation call for `app`, capturing which function
 * it was so the caller knows how many key arguments to read.
 *
 * Covers t(), n() and the $t/$n template variants, plus member forms like
 * this.t(...). The negative lookbehind rejects identifiers that merely END in
 * t or n -- format(, fn(, min( -- which a bare \b would let through for `n`.
 *
 * n() is the reason this exists. The previous extractor matched only `t(`, so
 * every n() plural key was invisible to it. That made check-l10n report all
 * plural keys as UNUSED, and armed clean-l10n.js: because it deletes
 * en.js-minus-used from EVERY locale file, adding the plural source keys to
 * en.js (which is correct and expected) would make the next --apply erase them
 * from all 37 locales. They are only safe today by accident of being absent.
 */
function translationCallRe(app) {
	return new RegExp(
		`(?<![\\w$])\\$?([tn])\\s*\\(\\s*(['"])${escapeRegex(app)}\\2\\s*,\\s*`,
		'g',
	)
}

/**
 * Read a single/double-quoted JS string literal starting at `start` (which must
 * be the opening quote). Returns { value, end } where `end` is the index of the
 * closing quote, or null when the literal is unterminated or spans a newline
 * (i.e. is not a simple static literal we can trust).
 */
function readStringLiteral(text, start) {
	const quote = text[start]
	if (quote !== '\'' && quote !== '"') return null
	let i = start + 1
	let value = ''
	while (i < text.length) {
		const c = text[i]
		if (c === '\\' && i + 1 < text.length) {
			const n = text[i + 1]
			// \uXXXX, \u{XXXXX} and \xXX must be DECODED, not stripped. The
			// runtime key is whatever JS produces, so an extractor that turns
			// '⚠' into the literal 'u26A0' reports a key that can never
			// match at runtime -- the translation would silently never apply.
			if (n === 'u' && text[i + 2] === '{') {
				const close = text.indexOf('}', i + 3)
				const hex = close === -1 ? null : text.slice(i + 3, close)
				if (hex && /^[0-9a-fA-F]{1,6}$/.test(hex)) {
					value += String.fromCodePoint(parseInt(hex, 16))
					i = close + 1
					continue
				}
			}
			if (n === 'u' && /^[0-9a-fA-F]{4}$/.test(text.slice(i + 2, i + 6))) {
				value += String.fromCharCode(parseInt(text.slice(i + 2, i + 6), 16))
				i += 6
				continue
			}
			if (n === 'x' && /^[0-9a-fA-F]{2}$/.test(text.slice(i + 2, i + 4))) {
				value += String.fromCharCode(parseInt(text.slice(i + 2, i + 4), 16))
				i += 4
				continue
			}
			if (n === 'n') value += '\n'
			else if (n === 't') value += '\t'
			else if (n === 'r') value += '\r'
			else if (n === 'b') value += '\b'
			else if (n === 'f') value += '\f'
			else if (n === 'v') value += '\v'
			else if (n === '0' && !/[0-9]/.test(text[i + 2] || '')) value += '\0'
			else value += n
			i += 2
			continue
		}
		if (c === quote) return { value, end: i }
		if (c === '\n') return null
		value += c
		i++
	}
	return null
}

/**
 * Extract every static translation call for `app` from one file's text.
 *
 * Returns { calls, unanalyzable }:
 *   calls        [{ fn, keys, index }] -- `keys` holds 1 entry for t(), and 2
 *                for n() (singular AND plural).
 *
 * Only the SINGULAR of an n() is a catalogue key -- its value is the forms array,
 * and the plural source never gets an entry of its own. The plural is reported
 * here anyway so it counts as "used": that keeps clean-l10n.js from deleting a
 * key that happens to be some other call's singular, and makes findKeyReferences
 * block a removal the plural argument still mentions. Over-counting in the "used"
 * direction is safe; the reverse would delete live keys.
 *   unanalyzable [{ index }] -- calls whose key argument is not a static string
 *                literal (template literal, concatenation, variable).
 *
 * A t() call is accepted only when the literal is followed by `,` or `)`, which
 * rejects concatenations like t('app', 'a' + b). For n() the singular must be
 * followed by `,`; the plural by `,` or `)`.
 */
function extractTranslationCalls(text, app) {
	const re = translationCallRe(app)
	const calls = []
	const unanalyzable = []
	let m
	re.lastIndex = 0
	while ((m = re.exec(text)) !== null) {
		const fn = m[1]
		const wanted = fn === 'n' ? 2 : 1
		const keys = []
		let pos = re.lastIndex
		let ok = true
		for (let a = 0; a < wanted; a++) {
			const lit = readStringLiteral(text, pos)
			if (!lit) { ok = false; break }
			keys.push(lit.value)
			let j = lit.end + 1
			while (j < text.length && (text[j] === ' ' || text[j] === '\t' || text[j] === '\n')) j++
			const next = text[j]
			const isLast = a === wanted - 1
			if (next !== ',' && !(isLast && next === ')')) { ok = false; break }
			if (next !== ',') break
			pos = j + 1
			while (pos < text.length && /\s/.test(text[pos])) pos++
		}
		if (ok && keys.length === wanted) calls.push({ fn, keys, index: m.index })
		else unanalyzable.push({ index: m.index })
	}
	return { calls, unanalyzable }
}

/**
 * Source extensions every "is this key used?" scan walks.
 *
 * ONE list, exported, because the two directions this feeds are not symmetric in
 * consequence. check-l10n.js (the CI gate) asserts en.js COVERS what src/ uses;
 * clean-l10n.js --apply DELETES from all 37 locales what src/ does not. A file
 * type the gate scans but the cleaner does not is a silent data-loss path: the
 * gate stays green while --apply drops that file's keys from every locale. The
 * gate's list was the broader of the two, so this takes it wholesale rather than
 * narrowing to the intersection.
 *
 * `.mjs`/`.jsx`/`.tsx` match nothing in src/ today. They are here for the day one
 * appears, which is exactly the day the divergence would have cost something.
 */
const SRC_EXTS = ['.vue', '.js', '.ts', '.mjs', '.jsx', '.tsx']

/**
 * Scan src/ for translation calls and return the set of literal keys
 * referenced. Shared by check-l10n.js, clean-l10n.js and l10n-ai.js so
 * "is this key still in use?" answers stay consistent across all three.
 */
function collectUsedKeys(srcDir, app) {
	const used = new Set()
	for (const file of walk(srcDir, SRC_EXTS)) {
		const { calls } = extractTranslationCalls(fs.readFileSync(file, 'utf8'), app)
		for (const c of calls) for (const k of c.keys) used.add(k)
	}
	return used
}

/** Build a char-offset -> 1-based line resolver for one file's text. */
function makeLineResolver(text) {
	const lineStarts = [0]
	for (let i = 0; i < text.length; i++) {
		if (text.charCodeAt(i) === 10) lineStarts.push(i + 1)
	}
	return (pos) => {
		let lo = 0; let hi = lineStarts.length - 1
		while (lo < hi) {
			const mid = (lo + hi + 1) >> 1
			if (lineStarts[mid] <= pos) lo = mid
			else hi = mid - 1
		}
		return lo + 1
	}
}

/**
 * Find the file:line of every static translation reference to `key`. Used by
 * l10n-ai.js rm to explain *why* a removal is refused. Matches n() plural
 * arguments too, so removing a plural key is correctly blocked.
 */
function findKeyReferences(srcDir, app, key) {
	const hits = []
	for (const file of walk(srcDir, SRC_EXTS)) {
		const text = fs.readFileSync(file, 'utf8')
		const { calls } = extractTranslationCalls(text, app)
		if (!calls.length) continue
		const posToLine = makeLineResolver(text)
		for (const c of calls) {
			if (c.keys.includes(key)) hits.push({ file, line: posToLine(c.index) })
		}
	}
	return hits
}

/**
 *
 */
function escapeRegex(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * List l10n/*.js files in an app's l10n/ directory. Sorted for deterministic
 * output. Returns absolute paths.
 */
function listJsLocaleFiles(l10nDir) {
	if (!fs.existsSync(l10nDir)) return []
	return fs.readdirSync(l10nDir)
		.filter((f) => f.endsWith('.js'))
		.sort()
		.map((f) => path.join(l10nDir, f))
}

/** Strip the `.js` extension from a locale file basename ("en.js" → "en"). */
function localeNameOf(file) {
	return path.basename(file, '.js')
}

/**
 * Keys passed to t() through a VARIABLE, so no static scan can find them.
 * They are real, live keys, and anything listed here must be treated as USED:
 * never reported unused, never removed by a cleaner.
 *
 * App-specific — this is the one part deliberately NOT shared with
 * openregister's copy.
 *
 * These are the rule editor's option lists. Nine call sites pass a VARIABLE to
 * t(), so no static scan can see them:
 *
 *   ACTION_TYPES / TIMING_OPTIONS / ACTION_OPTIONS (views/Rule/ruleDraft.js)
 *     → `t('integriq', entry.label)` in RuleActionConfig.vue,
 *       RuleDetailPage.vue and modals/v2/RuleEditorModal.vue
 *   OPERATORS (views/Rule/RuleConditionLeaf.vue)
 *     → `t('integriq', op.label)` and `t('integriq', op.group)`
 *   the field rows in views/Rule/actionForms/{Approval,Authentication,
 *     Locking,WebhookSignature}Form.vue → `t('integriq', row.label)`
 *
 * This list used to be empty, on the stated grounds that integriq reaches all
 * its dynamic UI copy through src/manifest.json. It does not, and the cost was
 * two-sided: nine of these were in en.js, reported UNUSED, and offered up by
 * clean:l10n for deletion — deleting a live translation leaves the English
 * source rendering correctly, so nothing would have failed. The other sixty
 * were not in en.js at all, which is why the whole rule editor rendered
 * untranslated in every locale. They were added to en.js alongside this list.
 *
 * Regenerate by collecting `label:` and `group:` literals from the six modules
 * named above; do not hand-edit one entry without re-checking the rest.
 *
 * Add an entry — with the call site — when a variable-keyed t() call is
 * introduced, or the key silently stops being translated.
 */
const DYNAMIC_KEYS = [
	'API key',
	'After',
	'Approval',
	'Authentication',
	'Basic (users/groups)',
	'Before',
	'Dead-letter for later review',
	'Delete (Delete)',
	'Download',
	'Error',
	'Extend external input',
	'Extend input',
	'Fetch File',
	'Filepart Upload',
	'Fileparts Create',
	'Get (Read)',
	'GitHub (sha256=)',
	'JWT',
	'JWT (ZGW)',
	'JavaScript',
	'Lock resource',
	'Locking',
	'Mapping',
	'Nextcloud session (users/groups)',
	'OAuth (users/groups)',
	'OpenConnector (t=,v1=)',
	'Post (Create)',
	'Put (Update)',
	'Return an error to the caller',
	'Save object',
	'Skip (resolve without writing)',
	'Stripe (t=,v1=)',
	'Synchronization',
	'Unlock resource',
	'Upload',
	'Webhook signature',
	'Write File',
	'add',
	'all (collection, predicate)',
	'arithmetic',
	'array',
	'comparison',
	'concatenate strings',
	'control',
	'divide',
	'does not equal',
	'equals',
	'exists / truthy',
	'filter (collection, predicate)',
	'greater than',
	'greater than or equal',
	'if (condition, then, else)',
	'in (string contains / array member)',
	'less than',
	'less than or equal',
	'map (collection, predicate)',
	'merge arrays',
	'missing (list of required paths)',
	'missing / falsy',
	'modulo',
	'multiply',
	'negation',
	'none (collection, predicate)',
	'reduce (collection, predicate)',
	'some (collection, predicate)',
	'string',
	'substring',
	'subtract',
	'var (read value at path)',
]

/**
 * Every key reached dynamically: DYNAMIC_KEYS plus the src/manifest.json fields
 * that MainMenu.translate(key) passes straight to t().
 *
 * Only fields that are actually resolved through a `translate` prop count.
 * Anything else in the manifest is data, not UI copy — notably
 * `observability.metrics[].name` (Prometheus metric identifiers), which made
 * metric names look like catalogue keys and would have put them in front of
 * translators.
 *
 * WHAT COUNTS, AND HOW IT WAS ESTABLISHED
 * ---------------------------------------
 * Measured 2026-09-06 against a Nextcloud 34 instance with the user's language
 * set to `nl` and the app's `l10n/` deployed, reading the rendered DOM:
 *
 *   - `menu[].label`, recursively through `children` — Sources rendered
 *     "Bronnen", Sync runs rendered "Synchronisatieruns".
 *   - `pages[].title` — /messages/stuf rendered its heading as
 *     "StUF-berichten", not "StUF messages".
 *   - report `cards[].label` and `cards[].description` — the Reports hub
 *     rendered "StUF-berichten" and "Wat elke StUF-uitwisseling bevatte".
 *     CnReportsPage builds `resolvedCards` with `this.tr(card.label)`.
 *
 * The last two were previously excluded here, on the stated grounds that
 * CnPageRenderer "forwards [title] to the page component as a raw prop without
 * translating it". That is no longer true, and the cost of the stale exclusion
 * is not cosmetic: four live keys were reported UNUSED, and `clean:l10n`
 * proposes deleting exactly what this function fails to claim. Deleting a
 * translated page title removes the Dutch string and leaves the English source
 * rendering correctly, so nothing would have failed.
 *
 * Verify the same way before adding a field: set a user to `nl`, deploy `l10n/`
 * (the built bundle does NOT carry it), and read the DOM. A field asserted from
 * source alone is a guess.
 *
 * NOTE: `scripts/lib/l10n.js` is vendored from openregister. This function now
 * diverges beyond DYNAMIC_KEYS; openregister needs the same correction.
 *
 * @param {string} repoRoot Absolute path to the app root.
 * @return {Set<string>} Keys that must count as used.
 */
function collectDynamicKeys(repoRoot) {
	const out = new Set(DYNAMIC_KEYS)
	const manifestPath = path.join(repoRoot, 'src/manifest.json')
	if (!fs.existsSync(manifestPath)) return out
	let manifest
	try { manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) } catch { return out }
	const add = (v) => { if (typeof v === 'string' && v.trim() !== '') out.add(v) }
	;(function collectMenu(items) {
		if (!Array.isArray(items)) return
		for (const item of items) {
			if (!item || typeof item !== 'object') continue
			add(item.label)
			collectMenu(item.children)
		}
	})(manifest.menu)
	for (const field of ['roadmapLabel', 'documentationLabel']) {
		add(manifest.nav?.[field])
	}
	// Every user-visible string anywhere in the manifest, by FIELD NAME.
	//
	// Field-scoped rather than "every string": `observability.metrics[].name`
	// holds Prometheus identifiers, and harvesting those made metric names look
	// like catalogue keys and put them in front of translators. `name` is not a
	// visible field, so it stays out.
	//
	// This list is deliberately the same one gate-102 (manifest-l10n-coverage)
	// checks. When the two disagreed, a sweep of "unused" keys deleted eight
	// manifest strings — walkthrough copy, column labels, a header action — and
	// gate-102 was the only thing that noticed. Keep them in step.
	const VISIBLE_FIELDS = new Set([
		'title',
		'label',
		'description',
		'body',
		'task',
		'emptyText',
		'emptyLabel',
		'placeholder',
		'subtitle',
		'helpText',
	])
	;(function harvest(node) {
		if (Array.isArray(node)) {
			for (const item of node) harvest(item)
			return
		}
		if (!node || typeof node !== 'object') return
		for (const [key, value] of Object.entries(node)) {
			if (VISIBLE_FIELDS.has(key)) add(value)
			harvest(value)
		}
	})(manifest.pages)
	// Report categories are a map of id -> label, so the label is the VALUE.
	for (const page of manifest.pages ?? []) {
		for (const label of Object.values(page?.config?.categories ?? {})) {
			add(label)
		}
	}
	harvestWalkthrough(manifest, add)
	return out
}

/**
 * Walkthrough tour copy: `walkthrough.tours[].steps[].{title,body,task}` plus
 * the tour titles. Rendered by CnWalkthrough through the same translate prop.
 *
 * @param {object} manifest The parsed manifest.
 * @param {Function} add Adder that ignores non-strings.
 * @return {void}
 */
function harvestWalkthrough(manifest, add) {
	for (const tour of manifest.walkthrough?.tours ?? []) {
		add(tour?.title)
		for (const step of tour?.steps ?? []) {
			add(step?.title)
			add(step?.body)
			add(step?.task)
		}
	}
	for (const step of manifest.setup?.steps ?? []) {
		add(step?.title)
		add(step?.body)
	}
}

/**
 * Every string the BACKEND translates, harvested from PHP `->t()` calls.
 *
 * These are invisible to a scan of `src/`, and they must count as used for the
 * same reason DYNAMIC_KEYS must: `l10n/<locale>.js` is GENERATED from
 * `l10n/<locale>.json`, and the JSON is the catalogue PHP `IL10N` reads. A
 * backend-only string therefore appears in `en.js` legitimately, and reporting
 * it "unused" is a false positive of the frontend scan, not a finding.
 *
 * CLAUDE.md used to say there was "no scanner for the backend set" and that
 * auditing it "would mean walking lib/ for PHP $l->t() calls". This is that
 * walk. Measured 2026-09-07: 409 distinct strings reach PHP `->t()` in lib/,
 * and 71 of them were being reported as UNUSED frontend keys and offered up by
 * clean:l10n. Deleting one removes the backend's translation and leaves the
 * English source rendering correctly, so nothing fails.
 *
 * Deliberately generous about what a `->t(` is: any object's `t()` taking a
 * literal first argument. A false POSITIVE here only keeps a key alive, which
 * is the safe direction; a false negative deletes a live translation.
 *
 * @param {string} repoRoot Absolute path to the app root.
 * @return {Set<string>} Keys the backend translates.
 */
function collectBackendKeys(repoRoot) {
	const out = new Set()
	const libDir = path.join(repoRoot, 'lib')
	if (!fs.existsSync(libDir)) return out
	const unescape = (raw, quote) =>
		raw.replace(new RegExp('\\\\' + quote, 'g'), quote).replace(/\\\\/g, '\\')
	for (const file of walk(libDir, ['.php'])) {
		const source = fs.readFileSync(file, 'utf8')
		for (const match of source.matchAll(/->t\(\s*'((?:[^'\\]|\\.)*)'/g)) {
			out.add(unescape(match[1], "'"))
		}
		for (const match of source.matchAll(/->t\(\s*"((?:[^"\\]|\\.)*)"/g)) {
			out.add(unescape(match[1], '"'))
		}
	}
	return out
}

/**
 * Every user-visible string a shipped REGISTER descriptor declares: schema
 * titles, and each property's `title` and `description`.
 *
 * These reach the user through OpenRegister's form renderer, not through any
 * t() call in src/ and not through PHP, so both other collectors miss them.
 * `scripts/check-schema-l10n.js` audits their coverage from the other side and
 * holds a baseline; this is what stops the same strings being reported UNUSED
 * by check:l10n and swept.
 *
 * Learned the hard way: a sweep of 595 "unused" keys removed 55 of these, and
 * check:schema-l10n was the only thing that noticed, on CI rather than locally
 * because it is not part of check:strict.
 *
 * @param {string} repoRoot Absolute path to the app root.
 * @return {Set<string>} Keys the register descriptors declare.
 */
function collectSchemaKeys(repoRoot) {
	const out = new Set()
	const settingsDir = path.join(repoRoot, 'lib', 'Settings')
	if (!fs.existsSync(settingsDir)) return out
	const add = (value) => {
		if (typeof value === 'string' && value.trim() !== '') out.add(value)
	}
	// Deliberately the SAME walk as scripts/check-schema-l10n.js `collect()`:
	// recurse everywhere, and at any node carrying `properties` harvest the
	// schema title, each property's title/description, and x-enum-labels.
	// A one-level version missed nested shapes
	// (`event_subscription.properties.action.properties.*`) and every enum
	// label. The two must not drift: one decides coverage, the other decides
	// what a sweep may delete.
	const collect = (node) => {
		if (Array.isArray(node)) {
			for (const item of node) collect(item)
			return
		}
		if (node === null || typeof node !== 'object') return
		const props = node.properties
		if (props !== null && typeof props === 'object' && !Array.isArray(props)) {
			add(node.title)
			for (const prop of Object.values(props)) {
				if (prop === null || typeof prop !== 'object') continue
				add(prop.title)
				add(prop.description)
				for (const source of [prop, prop.items]) {
					if (source === null || typeof source !== 'object') continue
					const labels = source['x-enum-labels']
					if (labels === null || typeof labels !== 'object') continue
					for (const label of Object.values(labels)) add(label)
				}
			}
		}
		for (const value of Object.values(node)) collect(value)
	}
	for (const file of walk(settingsDir, ['.json'])) {
		try {
			collect(JSON.parse(fs.readFileSync(file, 'utf8')))
		} catch {
			continue
		}
	}
	return out
}

module.exports = {
	loadJsTranslations,
	serializeJs,
	walk,
	extractTranslationCalls,
	makeLineResolver,
	collectUsedKeys,
	findKeyReferences,
	SRC_EXTS,
	listJsLocaleFiles,
	localeNameOf,
	DYNAMIC_KEYS,
	collectDynamicKeys,
	collectBackendKeys,
	collectSchemaKeys,
}
