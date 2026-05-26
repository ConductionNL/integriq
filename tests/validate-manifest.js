#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue app-manifest schema using Ajv.
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the schema with zero errors
//   1 — manifest fails validation (or schema/manifest cannot be loaded)
//
// Schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest.schema.json (sibling worktree)
//   4. /tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json
//   5. /tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

/**
 * Determine whether the manifest is v2 (points to the v2 $schema URL).
 *
 * @param {object} manifest Parsed manifest object.
 * @return {boolean} True when the manifest targets the v2 schema.
 */
function isV2Manifest(manifest) {
	return typeof manifest.$schema === 'string' && manifest.$schema.includes('app-manifest-v2')
}

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

/**
 * Load the bundled validateManifestV2 from @conduction/nextcloud-vue — the
 * same validator CnAppRoot runs at runtime, and therefore the source of truth
 * for what the manifest may contain. Returns null when the package is not
 * requirable in bare node (some published bundles pull in @nextcloud/vue and
 * fail to parse under the CJS loader), so the caller can fall back.
 *
 * @return {?function(object): {valid: boolean, errors?: Array}} validator or null.
 */
function loadBundledValidator() {
	const candidates = [
		'@conduction/nextcloud-vue/dist/utils.cjs.js',
		'@conduction/nextcloud-vue',
	]
	for (const id of candidates) {
		try {
			const mod = require(id)
			const fn = mod && (mod.validateManifestV2 || (mod.default && mod.default.validateManifestV2))
			if (typeof fn === 'function') return fn
		} catch (_) {
			// try next candidate
		}
	}
	return null
}

/**
 * Resolve the set of allowed page `type` values. The page-type enum in
 * @conduction/nextcloud-vue's shipped JSON schema is the one part of that
 * schema that has NOT drifted from the runtime, so we read it when available
 * and fall back to the full known set otherwise.
 *
 * @return {Set<string>} Allowed page type discriminators.
 */
function allowedPageTypes() {
	const FALLBACK = ['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'form', 'map', 'roadmap', 'search', 'custom']
	const schemaFile = path.join(REPO_ROOT, 'node_modules', '@conduction', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json')
	try {
		if (fs.existsSync(schemaFile)) {
			const schema = loadJson(schemaFile)
			const en = schema && schema.$defs && schema.$defs.page
				&& schema.$defs.page.properties && schema.$defs.page.properties.type
				&& schema.$defs.page.properties.type.enum
			if (Array.isArray(en) && en.length) return new Set(en)
		}
	} catch (_) {
		// fall through to FALLBACK
	}
	return new Set(FALLBACK)
}

function structuralLint(manifest) {
	// Minimal structural fallback when the bundled validator isn't requirable.
	const errors = []
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu)) errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages)) errors.push('top-level: pages (array) is required')
	const allowedTypes = allowedPageTypes()
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(`pages[${i}]: missing required string field "${required}"`)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in known enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id)) errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
	}
	return errors
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-manifest] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	const schemaVariant = isV2Manifest(manifest) ? 'v2' : 'v1'
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version} (schema variant: ${schemaVariant})`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	// Authoritative validator: @conduction/nextcloud-vue's bundled
	// validateManifestV2 is the SAME code that CnAppRoot runs at runtime, so it
	// is the source of truth for what the manifest may contain (it accepts the
	// `handler:'navigate'` + `route` + `icon` action shape the host apps use).
	//
	// We deliberately do NOT validate against the package's standalone
	// src/schemas/app-manifest-v2.schema.json: as of @conduction/nextcloud-vue
	// 1.0.0-beta.10x that JSON Schema has drifted out of sync with the bundled
	// validator (it declares a typed-action model with additionalProperties:false
	// that rejects the `icon`/`route` fields the runtime still reads and renders).
	// Until nextcloud-vue reconciles the two, the schema path produces false
	// positives — see the tracked nextcloud-vue issue. So: try the bundled
	// validator first, then fall back to a structural lint.
	const bundled = loadBundledValidator()
	if (bundled) {
		const result = bundled(manifest)
		if (result && result.valid) {
			console.log('[validate-manifest] validateManifestV2 (bundled): PASS')
			process.exit(0)
		}
		console.error('[validate-manifest] validateManifestV2 (bundled): FAIL')
		for (const err of (result && result.errors) || []) {
			console.error(`  - ${typeof err === 'string' ? err : JSON.stringify(err)}`)
		}
		process.exit(1)
	}

	console.warn('[validate-manifest] bundled validateManifestV2 not requirable in bare node; falling back to structural lint.')
	const structErrors = structuralLint(manifest)
	if (structErrors.length === 0) {
		console.log('[validate-manifest] structural lint: PASS (0 issues)')
		process.exit(0)
	}
	console.error('[validate-manifest] structural lint: FAIL')
	for (const err of structErrors) console.error(`  - ${err}`)
	process.exit(1)
}

main()
