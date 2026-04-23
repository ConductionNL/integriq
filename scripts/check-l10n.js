#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * l10n/i18n consistency checker for openconnector.
 *
 * Scans src/ (*.vue, *.js, *.ts) and compares against l10n/en.js.
 *
 * Reports:
 *   1. MISSING   — strings used via t('openconnector', '...') but absent from en.js
 *   2. UNUSED    — keys defined in en.js with no matching t() call
 *   3. UNWRAPPED — string literals in .vue files that match an en.js key but
 *                  are not wrapped in t() (likely missing translation)
 *
 * Exits non-zero if any issues are found.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const L10N_FILE = path.join(ROOT, 'l10n', 'en.js')

const RED = '\x1b[31m'
const YELLOW = '\x1b[33m'
const GREEN = '\x1b[32m'
const CYAN = '\x1b[36m'
const DIM = '\x1b[2m'
const BOLD = '\x1b[1m'
const RESET = '\x1b[0m'

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

function rel(p) {
	return path.relative(ROOT, p)
}

function loadKeys() {
	const code = fs.readFileSync(L10N_FILE, 'utf8')
	let captured = null
	const sandbox = {
		OC: {
			L10N: {
				register: (_app, translations) => { captured = translations },
			},
		},
	}
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: L10N_FILE })
	if (!captured || typeof captured !== 'object') {
		throw new Error(`OC.L10N.register was not called with a translations object in ${L10N_FILE}`)
	}
	return new Set(Object.keys(captured))
}

/**
 * Extract t('openconnector', '...') calls.
 * Captures simple string-literal args (single or double quotes, with escapes).
 * Returns { found: Map<key, [{file,line}]>, unanalyzable: [{file,line,snippet}] }.
 */
function extractTCalls(files) {
	const found = new Map()
	const unanalyzable = []

	// Match t('openconnector', ...) or t("openconnector", ...)
	// We find the "t(" occurrence, then parse the arg list manually to handle
	// template literals / concatenation gracefully.
	const tCallRe = /\bt\s*\(\s*(['"])openconnector\1\s*,\s*/g

	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		// Build line offsets once
		const lineStarts = [0]
		for (let i = 0; i < text.length; i++) {
			if (text.charCodeAt(i) === 10) lineStarts.push(i + 1)
		}
		const posToLine = (pos) => {
			// binary search
			let lo = 0; let hi = lineStarts.length - 1
			while (lo < hi) {
				const mid = (lo + hi + 1) >> 1
				if (lineStarts[mid] <= pos) lo = mid
				else hi = mid - 1
			}
			return lo + 1
		}

		let m
		while ((m = tCallRe.exec(text)) !== null) {
			const argStart = tCallRe.lastIndex
			const line = posToLine(m.index)
			const ch = text[argStart]
			if (ch === '\'' || ch === '"') {
				// Parse simple quoted string with \\ escapes
				let i = argStart + 1
				let value = ''
				let closed = false
				while (i < text.length) {
					const c = text[i]
					if (c === '\\' && i + 1 < text.length) {
						const n = text[i + 1]
						// Keep common escapes literal
						if (n === 'n') value += '\n'
						else if (n === 't') value += '\t'
						else if (n === 'r') value += '\r'
						else value += n
						i += 2
						continue
					}
					if (c === ch) { closed = true; break }
					if (c === '\n') break // unterminated on this line
					value += c
					i++
				}
				if (closed) {
					// Ensure next non-space char is , or )  — i.e. it's the full arg, not "'foo' + bar"
					let j = i + 1
					while (j < text.length && (text[j] === ' ' || text[j] === '\t')) j++
					const next = text[j]
					if (next === ',' || next === ')') {
						if (!found.has(value)) found.set(value, [])
						found.get(value).push({ file, line })
						continue
					}
				}
				// Fell through — unanalyzable
				unanalyzable.push({ file, line, snippet: text.slice(m.index, Math.min(m.index + 80, text.length)).replace(/\n/g, ' ') })
			} else {
				// template literal, variable, concatenation, etc.
				unanalyzable.push({ file, line, snippet: text.slice(m.index, Math.min(m.index + 80, text.length)).replace(/\n/g, ' ') })
			}
		}
	}

	return { found, unanalyzable }
}

/**
 * Find unwrapped static string literals in .vue files that match an l10n key.
 *
 * Scope: only .vue <template> blocks. We look for:
 *   - text between tags:  >Some text<
 *   - quoted attribute values on non-bound attrs:  title="Some text"
 * and skip anything inside {{ ... }} or on :bound / v-on attributes.
 *
 * This is heuristic and can produce false positives; each hit is reported with
 * file:line so humans can audit.
 */
function findUnwrapped(vueFiles, keys) {
	const hits = []
	for (const file of vueFiles) {
		const text = fs.readFileSync(file, 'utf8')
		const tplMatch = text.match(/<template[^>]*>([\s\S]*?)<\/template>/)
		if (!tplMatch) continue
		const tpl = tplMatch[1]
		const tplOffset = tplMatch.index + tplMatch[0].indexOf(tpl)

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

		// 1) Text between tags: match >TEXT< where TEXT has no < or { or }
		const textRe = />([^<>{}]+)</g
		let tm
		while ((tm = textRe.exec(tpl)) !== null) {
			const raw = tm[1]
			const trimmed = raw.trim()
			if (!trimmed) continue
			if (keys.has(trimmed)) {
				const absPos = tplOffset + tm.index + 1 + raw.indexOf(trimmed)
				hits.push({ file, line: posToLine(absPos), key: trimmed, context: 'text' })
			}
		}

		// 2) Static attribute values (unbound): attr="value" where attr does NOT start with : or @ or v-
		//    We scan tag-by-tag to know which attr we're on.
		const tagRe = /<[a-zA-Z][^>]*>/g
		let tag
		while ((tag = tagRe.exec(tpl)) !== null) {
			const tagText = tag[0]
			const tagAbs = tplOffset + tag.index
			const attrRe = /(\s)([:@]?[a-zA-Z_][\w-]*|v-[\w:.-]+)\s*=\s*("([^"]*)"|'([^']*)')/g
			let am
			while ((am = attrRe.exec(tagText)) !== null) {
				const name = am[2]
				if (name.startsWith(':') || name.startsWith('@') || name.startsWith('v-')) continue
				const value = am[4] !== undefined ? am[4] : am[5]
				const trimmed = value.trim()
				if (!trimmed) continue
				if (keys.has(trimmed)) {
					const valueOffsetInTag = am.index + am[0].indexOf(am[3]) + 1
					const absPos = tagAbs + valueOffsetInTag
					hits.push({ file, line: posToLine(absPos), key: trimmed, context: `attr ${name}` })
				}
			}
		}
	}
	return hits
}

function printSection(title, color, body) {
	console.log(`${color}${BOLD}${title}${RESET}`)
	console.log(body)
	console.log('')
}

function main() {
	const keys = loadKeys()
	const files = walk(SRC_DIR, ['.vue', '.js', '.ts'])
	const vueFiles = files.filter(f => f.endsWith('.vue'))

	const { found, unanalyzable } = extractTCalls(files)
	const usedKeys = new Set(found.keys())

	const missing = [...usedKeys].filter(k => !keys.has(k)).sort()
	const unused = [...keys].filter(k => !usedKeys.has(k)).sort()
	const unwrapped = findUnwrapped(vueFiles, keys)

	console.log(`${BOLD}${CYAN}openconnector l10n check${RESET}`)
	console.log(`${DIM}Scanned ${files.length} files (${vueFiles.length} .vue), ${keys.size} keys in en.js${RESET}`)
	console.log('')

	if (missing.length) {
		const body = missing.map(k => {
			const locs = found.get(k).map(l => `${DIM}${rel(l.file)}:${l.line}${RESET}`).join(', ')
			return `  ${RED}•${RESET} ${JSON.stringify(k)}\n    ${locs}`
		}).join('\n')
		printSection(`MISSING from l10n/en.js (${missing.length})`, RED, body)
	} else {
		printSection('MISSING from l10n/en.js (0)', GREEN, '  ✓ none')
	}

	if (unused.length) {
		const body = unused.map(k => `  ${YELLOW}•${RESET} ${JSON.stringify(k)}`).join('\n')
		printSection(`UNUSED keys in l10n/en.js (${unused.length})`, YELLOW, body)
	} else {
		printSection('UNUSED keys in l10n/en.js (0)', GREEN, '  ✓ none')
	}

	if (unwrapped.length) {
		const body = unwrapped.map(h =>
			`  ${YELLOW}•${RESET} ${JSON.stringify(h.key)} ${DIM}[${h.context}]${RESET}\n    ${DIM}${rel(h.file)}:${h.line}${RESET}`,
		).join('\n')
		printSection(`UNWRAPPED literals matching an l10n key (${unwrapped.length})`, YELLOW, body)
	} else {
		printSection('UNWRAPPED literals matching an l10n key (0)', GREEN, '  ✓ none')
	}

	if (unanalyzable.length) {
		const body = unanalyzable.map(u =>
			`  ${DIM}•${RESET} ${rel(u.file)}:${u.line}\n    ${DIM}${u.snippet}...${RESET}`,
		).join('\n')
		printSection(`Unanalyzable t() calls — dynamic args, skipped (${unanalyzable.length})`, DIM, body)
	}

	const total = missing.length + unused.length + unwrapped.length
	if (total > 0) {
		console.log(`${RED}${BOLD}✗ ${total} issue(s) found${RESET}`)
		process.exit(1)
	} else {
		console.log(`${GREEN}${BOLD}✓ all clean${RESET}`)
	}
}

main()
