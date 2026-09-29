#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-connector-templates.js: the shape of the connector template
// library (lib/Settings/connector-templates/<set>/*.json), which the Store
// lists and Instantiate turns into a source (connectors-catalogue-expansion).
//
//   - every template names its slug, vendor, system, standard and tier
//   - a curated template cites the interface it was checked against
//   - a generated template cites its directory entry and snapshot date
//   - no template carries a credential-shaped field
//   - the file is named after its slug, and every slug is unique
//
// Usage:   node tests/validate-connector-templates.js [dir]
// Exit:    0 = the library is sound   1 = problems found, each naming its file
//
// @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-a-back-office-template-names-its-standard-and-where-it-was-checked-req-ccx-002

'use strict'

const fs = require('fs')
const path = require('path')

const LIBRARY = path.resolve(
	__dirname,
	'..',
	'lib',
	'Settings',
	'connector-templates',
)
const NOT_TEMPLATES = new Set(['allow-list.json'])
const SECRET_KEY =
	/^(client_?secret|secret|password|api_?key|jwt|access_?token|refresh_?token|private_?key|ssl_key)$/i
const TIERS = ['curated', 'generated']

/**
 * Every key path in a value whose key looks like a credential.
 *
 * @param {object|Array} value A JSON value.
 * @param {string} at The path so far.
 * @return {string[]} The offending paths.
 */
function secretPaths(value, at) {
	if (value === null || typeof value !== 'object') {
		return []
	}
	return Object.entries(value).flatMap(([key, item]) => [
		...(SECRET_KEY.test(key) ? [`${at}.${key}`] : []),
		...secretPaths(item, `${at}.${key}`),
	])
}

/**
 * The problems of one template.
 *
 * @param {string} file The file name, for the message.
 * @param {object} data The parsed template.
 * @return {string[]} One message per problem, each naming the file.
 */
function problemsOf(file, data) {
	const meta = data['x-template']
	const source = data.source
	if (
		meta === null
		|| typeof meta !== 'object'
		|| source === null
		|| typeof source !== 'object'
	) {
		return [`${file}: needs an x-template block and a source payload`]
	}

	const problems = []
	for (const key of ['slug', 'vendor', 'system', 'standard']) {
		if (typeof meta[key] !== 'string' || meta[key].trim() === '') {
			problems.push(`${file}: x-template.${key} is missing`)
		}
	}
	if (!TIERS.includes(meta.tier)) {
		problems.push(`${file}: x-template.tier must be curated or generated`)
	}
	if (
		typeof meta.verifiedAgainst !== 'string'
		|| !/^https:\/\/\S+$/.test(meta.verifiedAgainst)
	) {
		problems.push(
			`${file}: x-template.verifiedAgainst must cite the https URL of the interface it was checked against`,
		)
	}
	if (
		meta.tier === 'generated'
		&& !/^\d{4}-\d{2}-\d{2}$/.test(meta.snapshotDate || '')
	) {
		problems.push(
			`${file}: a generated template needs x-template.snapshotDate (YYYY-MM-DD)`,
		)
	}
	if (
		typeof meta.slug === 'string'
		&& path.basename(file, '.json') !== meta.slug
	) {
		problems.push(
			`${file}: the file must be named after its slug ${meta.slug}.json`,
		)
	}
	for (const key of ['name', 'type', 'location']) {
		if (typeof source[key] !== 'string' || source[key].trim() === '') {
			problems.push(`${file}: source.${key} is missing`)
		}
	}
	if (source.isEnabled !== false) {
		problems.push(
			`${file}: source.isEnabled must be false; an administrator enables it after Instantiate`,
		)
	}
	for (const at of secretPaths(source, 'source')) {
		problems.push(
			`${file}: ${at} looks like a credential; hold it in the credential broker and name it by credentialRef`,
		)
	}
	return problems
}

/**
 * Validate a template library.
 *
 * @param {string} dir The library directory.
 * @return {string[]} Every problem found; empty when the library is sound.
 */
function validateLibrary(dir) {
	const problems = []
	const slugs = new Map()
	const sets = fs.existsSync(dir)
		? fs
				.readdirSync(dir, { withFileTypes: true })
				.filter((entry) => entry.isDirectory())
		: []
	for (const set of sets) {
		const files = fs
			.readdirSync(path.join(dir, set.name))
			.filter((name) => name.endsWith('.json') && !NOT_TEMPLATES.has(name))
		for (const name of files) {
			const file = `${set.name}/${name}`
			let data
			try {
				data = JSON.parse(
					fs.readFileSync(path.join(dir, set.name, name), 'utf8'),
				)
			} catch (error) {
				problems.push(`${file}: not valid JSON (${error.message})`)
				continue
			}
			problems.push(...problemsOf(file, data))
			const slug = data?.['x-template']?.slug
			if (typeof slug === 'string') {
				if (slugs.has(slug)) {
					problems.push(
						`${file}: slug ${slug} is also used by ${slugs.get(slug)}`,
					)
				}
				slugs.set(slug, file)
			}
		}
	}
	return problems
}

module.exports = { validateLibrary }

if (require.main === module) {
	const dir = process.argv[2] ? path.resolve(process.argv[2]) : LIBRARY
	const problems = validateLibrary(dir)
	if (problems.length > 0) {
		for (const problem of problems) {
			console.error(`  ✗ ${problem}`)
		}
		console.error(
			`\n${problems.length} problem(s) in the connector template library.`,
		)
		process.exit(1)
	}
	console.log('Connector template library: every template is sound.')
}
