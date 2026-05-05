/* eslint-disable jsdoc/require-param */
/**
 * Shared l10n helpers used by check-l10n.js, clean-l10n.js, and l10n-ai.js.
 *
 * Operates on l10n/*.js (frontend translation files). Backend .json files are
 * a separate concern and are not handled here.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')
const { spawnSync } = require('child_process')

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
 * Serialize an l10n/*.js file matching the existing project convention:
 * sorted keys (case-insensitive), tab indent, double-quoted strings.
 *
 * Pluralized values (arrays) are preserved as JSON arrays; the writer doesn't
 * re-pretty-print them, but eslint --fix afterwards normalizes spacing.
 */
function serializeJs({ app, translations, pluralForm }) {
	const keys = Object.keys(translations).sort((a, b) =>
		a.toLowerCase().localeCompare(b.toLowerCase()),
	)
	const lines = keys.map((k) => {
		const value = translations[k]
		return `\t\t${JSON.stringify(k)}: ${JSON.stringify(value)},`
	})
	return `OC.L10N.register(\n\t${JSON.stringify(app)},\n\t{\n${lines.join('\n')}\n\t},\n\t${JSON.stringify(pluralForm)},\n)\n`
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
 * Scan src/ for t('<app>', '...') calls and return the set of literal keys
 * referenced. Mirrors the extractor used by check-l10n.js / clean-l10n.js so
 * "is this key still in use?" answers stay consistent across all three tools.
 *
 * Only static single/double-quoted string args are recognized; template
 * literals and concatenations are skipped (caller handles "unanalyzable").
 */
function collectUsedKeys(srcDir, app) {
	const files = walk(srcDir, ['.vue', '.js', '.ts'])
	const used = new Set()
	const tCallRe = new RegExp(`\\bt\\s*\\(\\s*(['"])${escapeRegex(app)}\\1\\s*,\\s*`, 'g')

	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		tCallRe.lastIndex = 0
		while (tCallRe.exec(text) !== null) {
			const argStart = tCallRe.lastIndex
			const ch = text[argStart]
			if (ch !== '\'' && ch !== '"') continue
			let i = argStart + 1
			let value = ''
			let closed = false
			while (i < text.length) {
				const c = text[i]
				if (c === '\\' && i + 1 < text.length) {
					const n = text[i + 1]
					if (n === 'n') value += '\n'
					else if (n === 't') value += '\t'
					else if (n === 'r') value += '\r'
					else value += n
					i += 2
					continue
				}
				if (c === ch) { closed = true; break }
				if (c === '\n') break
				value += c
				i++
			}
			if (!closed) continue
			let j = i + 1
			while (j < text.length && (text[j] === ' ' || text[j] === '\t')) j++
			const next = text[j]
			if (next !== ',' && next !== ')') continue
			used.add(value)
		}
	}
	return used
}

/**
 * Find the file:line of every static t('<app>', '<key>') reference. Used by
 * l10n-ai.js rm to explain *why* a removal is refused.
 */
function findKeyReferences(srcDir, app, key) {
	const files = walk(srcDir, ['.vue', '.js', '.ts'])
	const hits = []
	const tCallRe = new RegExp(`\\bt\\s*\\(\\s*(['"])${escapeRegex(app)}\\1\\s*,\\s*`, 'g')

	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		const lineStarts = [0]
		for (let i = 0; i < text.length; i++) {
			if (text.charCodeAt(i) === 10) lineStarts.push(i + 1)
		}
		const posToLine = (pos) => {
			let lo = 0; let hi = lineStarts.length - 1
			while (lo < hi) {
				const mid = (lo + hi + 1) >> 1
				if (lineStarts[mid] <= pos) lo = mid
				else hi = mid - 1
			}
			return lo + 1
		}

		let m
		tCallRe.lastIndex = 0
		while ((m = tCallRe.exec(text)) !== null) {
			const argStart = tCallRe.lastIndex
			const ch = text[argStart]
			if (ch !== '\'' && ch !== '"') continue
			let i = argStart + 1
			let value = ''
			let closed = false
			while (i < text.length) {
				const c = text[i]
				if (c === '\\' && i + 1 < text.length) {
					const n = text[i + 1]
					if (n === 'n') value += '\n'
					else if (n === 't') value += '\t'
					else if (n === 'r') value += '\r'
					else value += n
					i += 2
					continue
				}
				if (c === ch) { closed = true; break }
				if (c === '\n') break
				value += c
				i++
			}
			if (!closed) continue
			let j = i + 1
			while (j < text.length && (text[j] === ' ' || text[j] === '\t')) j++
			const next = text[j]
			if (next !== ',' && next !== ')') continue
			if (value === key) hits.push({ file, line: posToLine(m.index) })
		}
	}
	return hits
}

function escapeRegex(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Run `eslint --fix` on a list of files using the project's local eslint.
 * Quiet no-op when node_modules isn't installed.
 */
function runEslintFix(files, { rootDir, log = () => {} } = {}) {
	if (!files.length) return
	const bin = path.join(rootDir, 'node_modules', '.bin', 'eslint')
	if (!fs.existsSync(bin)) {
		log('Skipping eslint --fix: local eslint not found (run npm install).')
		return
	}
	const result = spawnSync(bin, ['--fix', '--no-warn-ignored', ...files], {
		cwd: rootDir,
		stdio: 'inherit',
	})
	if (result.status !== 0) {
		log(`eslint exited with status ${result.status} — files may still have lint issues, but the fixable ones have been corrected.`)
	}
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

module.exports = {
	loadJsTranslations,
	serializeJs,
	walk,
	collectUsedKeys,
	findKeyReferences,
	runEslintFix,
	listJsLocaleFiles,
	localeNameOf,
}
