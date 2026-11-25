// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The custom-component registry loads its components on first render, so
 * they stay out of integriq-main.js (ADR-124 speed budget: the main bundle is
 * parsed on every page before the shell draws). Importing the registry pulls
 * in the whole published component library, so its wiring is read from the
 * source, and the lazy wrapper is exercised on its own with a stand-in.
 */
import { lazyComponent, resolveLazyComponent } from '@conduction/nextcloud-vue/src/utils/lazyComponent.js'
import { flushPromises, mount } from '@vue/test-utils'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

const root = join(__dirname, '..', '..')
const source = readFileSync(join(root, 'src/registry.js'), 'utf8')
const LAZY = /^const (\w+) = lazyComponent\(\(\) => import\('(\.\/[^']+\.vue)'\), '(\w+)'\)$/gm
const entries = [...source.matchAll(LAZY)].map(([, name, path, label]) => ({ name, path, label }))

describe('the custom-component registry', () => {
	it('imports no component eagerly', () => {
		expect(source).not.toMatch(/^import \w+ from '[^']+\.vue'/m)
		expect(source).not.toMatch(/^import [^\n]*\.vue'/m)
	})

	it('declares every component lazily, from a file that exists, under its own name', () => {
		expect(entries.length).toBeGreaterThan(40)
		for (const { name, path, label } of entries) {
			expect(label, name).toBe(name)
			expect(existsSync(join(dirname(join(root, 'src/registry.js')), path)), path).toBe(true)
		}
	})

	it('exports every lazy component it declares', () => {
		const exported = source.slice(source.indexOf('export default {'))
		for (const { name } of entries) {
			// Shorthand in the default export, or the component of a v2
			// `registry` entry.
			expect(exported, name).toMatch(new RegExp(`^\\t${name},$|component: ${name}\\b`, 'm'))
		}
	})
})

describe('a lazily registered component', () => {
	const Panel = defineComponent({
		name: 'Panel',
		props: { register: { type: String, default: '' } },
		emits: ['view'],
		setup(props, { emit, slots, expose }) {
			expose({ reload: () => `reloaded ${props.register}` })
			return () => h('button', {
				'data-testid': 'panel',
				onClick: () => emit('view', props.register),
			}, [props.register, slots.default?.()])
		},
	})

	it('renders the loaded component with its props, listeners, slots and ref', async () => {
		const Lazy = lazyComponent(() => Promise.resolve({ default: Panel }), 'Panel')
		const seen = []
		const Host = defineComponent({
			setup(props, { expose }) {
				const inner = { value: null }
				expose({ inner })
				return () => h(Lazy, {
					ref: (el) => { inner.value = el },
					register: 'integriq',
					onView: (value) => seen.push(value),
				}, { default: () => 'slot text' })
			},
		})
		const wrapper = mount(Host)
		await flushPromises()

		const button = wrapper.get('[data-testid="panel"]')
		expect(button.text()).toBe('integriqslot text')
		await button.trigger('click')
		expect(seen).toEqual(['integriq'])
		expect(wrapper.vm.inner.value.reload()).toBe('reloaded integriq')
		// The renderer reads the loaded definition (declared props) through this.
		expect(resolveLazyComponent(Lazy)).toBe(Panel)
	})

	it('renders nothing until the module arrives', async () => {
		let arrive
		const Lazy = lazyComponent(() => new Promise((resolve) => { arrive = resolve }), 'Panel')
		const wrapper = mount(Lazy, { props: { register: 'integriq' } })
		await flushPromises()
		expect(wrapper.find('[data-testid="panel"]').exists()).toBe(false)
		expect(resolveLazyComponent(Lazy)).toBeNull()

		arrive({ default: Panel })
		await flushPromises()
		expect(wrapper.get('[data-testid="panel"]').text()).toBe('integriq')
	})
})
