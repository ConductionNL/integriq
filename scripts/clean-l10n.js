#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * l10n unused-key remover for openconnector.
 *
 * Reuses the extraction logic from check-l10n.js to detect keys that exist in
 * l10n/en.js but are not referenced by any t('openconnector', '...') call in
 * src/. Those keys are removed from EVERY l10n/*.js file (English and all
 * translations).
 *
 * This script intentionally does NOT add missing keys. Adding a key without a
 * human-written translation would leave non-English files with English values
 * that t() then returns *as if* they were translated — overriding the normal
 * fallback to the source string. Missing keys should be added through the
 * regular l10n workflow (Transifex or a translator editing en.js directly).
 *
 * Usage:
 *   node scripts/clean-l10n.js           # dry-run: prints what would be removed
 *   node scripts/clean-l10n.js --apply   # actually remove the unused keys
 *
 * Safety: UNUSED detection is based purely on JS/Vue/TS usage. This is safe for
 * openconnector because l10n/*.js is frontend-only (PHP reads from l10n/*.json,
 * a separate file set). Do NOT run --apply on a project where .js and .json are
 * kept in sync with each other.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')
const { spawnSync } = require('child_process')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const L10N_DIR = path.join(ROOT, 'l10n')
const ENGLISH_FILE = path.join(L10N_DIR, 'en.js')

// ---------- CLI ----------

const args = new Set(process.argv.slice(2))
if (args.has('--help') || args.has('-h')) {
	console.log(fs.readFileSync(__filename, 'utf8').split('\n').slice(2, 27).join('\n'))
	process.exit(0)
}
const apply = args.has('--apply')

// ---------- Extraction (mirrors check-l10n.js) ----------

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

function loadTranslations(l10nFile) {
	const code = fs.readFileSync(l10nFile, 'utf8')
	let captured = null
	let plural = null
	const sandbox = {
		OC: {
			L10N: {
				register: (_app, translations, pluralForm) => {
					captured = translations
					plural = pluralForm
				},
			},
		},
	}
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: l10nFile })
	if (!captured || typeof captured !== 'object') {
		throw new Error(`OC.L10N.register was not called with a translations object in ${l10nFile}`)
	}
	return { translations: captured, plural: plural || 'nplurals=2; plural=(n != 1);' }
}

function collectUsedKeys() {
	const files = walk(SRC_DIR, ['.vue', '.js', '.ts'])
	const used = new Set()
	const tCallRe = /\bt\s*\(\s*(['"])openconnector\1\s*,\s*/g

	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
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

// ---------- Serialization ----------

function serialize(translations, plural) {
	const keys = Object.keys(translations).sort((a, b) =>
		a.toLowerCase().localeCompare(b.toLowerCase()),
	)
	const lines = keys.map(k =>
		`\t\t${JSON.stringify(k)}: ${JSON.stringify(translations[k])},`,
	)
	return `OC.L10N.register(\n\t'openconnector',\n\t{\n${lines.join('\n')}\n\t},\n\t${JSON.stringify(plural)},\n)\n`
}

// ---------- Main ----------

function main() {
	if (!fs.existsSync(ENGLISH_FILE)) {
		console.error(`English source file not found: ${ENGLISH_FILE}`)
		process.exit(1)
	}

	const { translations: english } = loadTranslations(ENGLISH_FILE)
	const existingKeys = new Set(Object.keys(english))
	const usedKeys = collectUsedKeys()
	const unused = [...existingKeys].filter(k => !usedKeys.has(k)).sort()

	console.log('openconnector l10n unused-key remover')
	console.log(`  Used keys in src/:  ${usedKeys.size}`)
	console.log(`  Keys in en.js:      ${existingKeys.size}`)
	console.log(`  Unused keys:        ${unused.length}`)
	console.log('')

	if (!unused.length) {
		console.log('Nothing to remove.')
		return
	}

	if (!apply) {
		console.log('Dry-run. Pass --apply to remove these keys from all l10n/*.js files.\n')
		for (const k of unused) console.log(`  - ${JSON.stringify(k)}`)
		return
	}

	const files = fs.readdirSync(L10N_DIR)
		.filter(f => f.endsWith('.js'))
		.map(f => path.join(L10N_DIR, f))

	const written = []
	for (const file of files) {
		const { translations, plural } = loadTranslations(file)
		const before = Object.keys(translations).length
		for (const k of unused) delete translations[k]
		const after = Object.keys(translations).length
		fs.writeFileSync(file, serialize(translations, plural))
		written.push(file)
		console.log(`${path.basename(file)}: ${before} → ${after} keys`)
	}

	runEslintFix(written)

	console.log('\nDone. Run `npm run check:l10n` to verify.')
}

/**
 * Run `eslint --fix` on the written l10n files so the serializer's
 * double-quoted output is reformatted to match project style. Skips gracefully
 * if the local eslint binary isn't available (e.g. node_modules not installed).
 */
function runEslintFix(files) {
	if (!files.length) return
	const bin = path.join(ROOT, 'node_modules', '.bin', 'eslint')
	if (!fs.existsSync(bin)) {
		console.log('\nSkipping eslint --fix: local eslint not found (run npm install).')
		return
	}
	console.log('\nRunning eslint --fix on l10n files...')
	const result = spawnSync(bin, ['--fix', '--no-warn-ignored', ...files], {
		cwd: ROOT,
		stdio: 'inherit',
	})
	if (result.status !== 0) {
		console.log(`eslint exited with status ${result.status} — files may still have lint issues, but the fixable ones have been corrected.`)
	}
}

main()
