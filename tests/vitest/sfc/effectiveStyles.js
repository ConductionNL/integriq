/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CSS a single-file component applies to its own template, as compiled
 * by @vue/compiler-sfc. Every `<style>` block is compiled the way vue-loader
 * compiles it (scoped blocks get the scope attribute, `src` blocks read
 * their file), and the result is listed rule by rule with its at-rule
 * context. Rules whose class selectors name a class the template never
 * mentions are dropped: they cannot match anything the component renders,
 * so a golden of this list stays equal when a component starts to share a
 * stylesheet that also carries rules for other components.
 *
 * @spec exclude test harness for characterization tests of component styles
 */

import { compileStyle, parse } from '@vue/compiler-sfc'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import postcss from 'postcss'

const SCOPE_ID = 'data-v-golden'

/**
 * Whether every class a selector names occurs in the template text.
 *
 * @param {string} selector One rule selector.
 * @param {string} template The component's template source.
 * @return {boolean} True when the rule can match the template.
 */
function matchesTemplate(selector, template) {
	const classes = selector.match(/\.-?[_a-zA-Z][\w-]*/g) || []
	return classes.every((cls) =>
		new RegExp(
			'(^|[^\\w-])' + cls.slice(1).replace(/-/g, '\\-') + '($|[^\\w-])',
		).test(template),
	)
}

/**
 * The rules one compiled stylesheet applies to the template.
 *
 * @param {string} css Compiled CSS.
 * @param {string} template The component's template source.
 * @return {string[]} One line per rule: context, selector and declarations.
 */
function rulesOf(css, template) {
	const lines = []
	postcss.parse(css).walkRules((rule) => {
		const selectors = rule.selectors.filter((s) => matchesTemplate(s, template))
		if (!selectors.length) {
			return
		}
		const context = []
		for (
			let parent = rule.parent;
			parent && parent.type === 'atrule';
			parent = parent.parent
		) {
			context.unshift('@' + parent.name + ' ' + parent.params)
		}
		const decls = []
		rule.walkDecls((decl) => {
			decls.push(
				decl.prop
					+ ': '
					+ decl.value
					+ (decl.important ? ' !important' : ''),
			)
		})
		lines.push(
			[...context, selectors.join(', ')].join(' ')
				+ ' { '
				+ decls.join('; ')
				+ ' }',
		)
	})
	return lines
}

/**
 * The effective, ordered CSS rules of a `.vue` file.
 *
 * @param {string} file Absolute path of the `.vue` file.
 * @return {string[]} One line per rule that can match the template.
 */
export function effectiveStyles(file) {
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	const template = descriptor.template ? descriptor.template.content : ''
	const rules = []
	for (const block of descriptor.styles) {
		const source = block.src
			? readFileSync(resolve(dirname(file), block.src), 'utf8')
			: block.content
		const out = compileStyle({
			source,
			filename: file,
			id: SCOPE_ID,
			scoped: block.scoped,
			preprocessLang: block.lang,
		})
		if (out.errors.length) {
			throw out.errors[0]
		}
		rules.push(...rulesOf(out.code, template))
	}
	return rules
}
