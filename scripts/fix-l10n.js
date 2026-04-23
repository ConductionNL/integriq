#!/usr/bin/env node
/* eslint-disable */
/**
 * l10n/i18n auto-fixer for openconnector.
 *
 * Reuses the extraction logic from check-l10n.js to detect MISSING and UNUSED keys
 * (see that script for the underlying rules), then applies the requested fixes
 * to every l10n/<lang>.js file:
 *
 *   - MISSING keys are added. For en.js the value equals the key. For other
 *     languages the English source string is used as a placeholder (Transifex
 *     or a translator should fill in the real translation later).
 *   - UNUSED keys are removed from every language file.
 *
 * Usage:
 *   node scripts/fix-l10n.js                # dry-run: prints planned changes, writes nothing
 *   node scripts/fix-l10n.js --add-missing  # only add missing keys
 *   node scripts/fix-l10n.js --remove-unused  # only remove unused keys
 *   node scripts/fix-l10n.js --all          # both
 *
 * Safety: UNUSED detection is based purely on JS/Vue/TS usage. This is safe for
 * openconnector because l10n/*.js is frontend-only (PHP reads from l10n/*.json,
 * which is a separate file set). Do NOT run --remove-unused on a project where
 * .js and .json are kept in sync with each other.
 *
 * Exits 0 on success (or on dry-run). Exits 1 if the l10n directory or the
 * English source file is missing.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const L10N_DIR = path.join(ROOT, 'l10n')
const ENGLISH_FILE = path.join(L10N_DIR, 'en.js')

// ---------- CLI ----------

const args = new Set(process.argv.slice(2))
const help = args.has('--help') || args.has('-h')
if (help) {
	console.log(fs.readFileSync(__filename, 'utf8').split('\n').slice(2, 30).join('\n'))
	process.exit(0)
}
const doAll = args.has('--all')
const doAdd = doAll || args.has('--add-missing')
const doRemove = doAll || args.has('--remove-unused')
const dryRun = !doAdd && !doRemove

// ---------- Shared extraction (mirrors check-l10n.js) ----------

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

/**
 * Scan src/ for t('openconnector', '...') calls and return the set of keys used
 * (only statically-resolvable single/double-quoted string args; template literals
 * and concatenated args are ignored — same behavior as check-l10n.js).
 */
function collectUsedKeys() {
	const files = walk(SRC_DIR, ['.vue', '.js', '.ts'])
	const used = new Set()
	const tCallRe = /\bt\s*\(\s*(['"])openconnector\1\s*,\s*/g

	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		let m
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

function fmtCount(n, label) {
	return `${n} ${label}${n === 1 ? '' : 's'}`
}

function main() {
	if (!fs.existsSync(L10N_DIR)) {
		console.error(`l10n directory not found: ${L10N_DIR}`)
		process.exit(1)
	}
	if (!fs.existsSync(ENGLISH_FILE)) {
		console.error(`English source file not found: ${ENGLISH_FILE}`)
		process.exit(1)
	}

	const { translations: english } = loadTranslations(ENGLISH_FILE)
	const existingKeys = new Set(Object.keys(english))
	const usedKeys = collectUsedKeys()

	const missing = [...usedKeys].filter(k => !existingKeys.has(k)).sort()
	const unused = [...existingKeys].filter(k => !usedKeys.has(k)).sort()

	console.log(`openconnector l10n fixer`)
	console.log(`  Used keys in src/:     ${usedKeys.size}`)
	console.log(`  Keys in l10n/en.js:    ${existingKeys.size}`)
	console.log(`  ${fmtCount(missing.length, 'missing key')} (in code but not in en.js)`)
	console.log(`  ${fmtCount(unused.length, 'unused key')}  (in en.js but not in code)`)
	console.log('')

	if (dryRun) {
		console.log('Dry-run (no flags). Nothing will be written.')
		console.log('Pass --add-missing, --remove-unused, or --all to apply changes.\n')
		if (missing.length) {
			console.log('Would ADD:')
			for (const k of missing) console.log(`  + ${JSON.stringify(k)}`)
			console.log('')
		}
		if (unused.length) {
			console.log('Would REMOVE:')
			for (const k of unused) console.log(`  - ${JSON.stringify(k)}`)
			console.log('')
		}
		return
	}

	const files = fs.readdirSync(L10N_DIR)
		.filter(f => f.endsWith('.js'))
		.map(f => path.join(L10N_DIR, f))

	for (const file of files) {
		const { translations, plural } = loadTranslations(file)
		const before = Object.keys(translations).length
		const isEnglish = path.basename(file) === 'en.js'

		if (doRemove) {
			for (const k of unused) delete translations[k]
		}
		if (doAdd) {
			for (const k of missing) {
				if (!(k in translations)) {
					// en.js: value = key (the English source string).
					// Other languages: also use the English key as a placeholder; a
					// translator / Transifex sync should replace this later.
					translations[k] = k
				}
			}
		}

		const after = Object.keys(translations).length
		fs.writeFileSync(file, serialize(translations, plural))
		console.log(`${path.basename(file)}: ${before} → ${after} keys${isEnglish ? '' : ' (new entries use English placeholders)'}`)
	}

	console.log('')
	console.log('Done. Run `npm run check:l10n` to verify.')
	if (doAdd) {
		console.log('Note: newly-added keys in non-English files use English placeholders — translate them or sync via Transifex.')
	}
}

main()
