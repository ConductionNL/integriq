// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression guard for the @nextcloud/vue 8 -> 9 NcButton prop rename.
 *
 * In v8 NcButton had TWO props: `type` (the visual variant: primary,
 * secondary, error, ...) and `nativeType` (the DOM button type: submit,
 * reset, button). In v9 the visual prop was renamed to `variant` and `type`
 * was repurposed as the DOM button type, with `default: "button"`.
 * `nativeType` was removed entirely.
 *
 * The failure is silent: `native-type="submit"` on v9 is an unknown prop, so
 * it lands in the DOM as an inert attribute while the button keeps
 * type="button". A type="button" inside a <form> raises no submit event, so
 * an @submit.prevent handler never runs -- with no console warning, no lint
 * error and no failed request. It looks exactly like a backend that was
 * never called.
 *
 * These tests mount the REAL NcButton from the installed @nextcloud/vue and
 * assert against actual DOM behaviour, including negative controls that pin
 * the broken spellings. If a future @nextcloud/vue reinstates `nativeType`,
 * the negative controls fail loudly rather than silently passing.
 */

import { describe, it, expect } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import NcButton from '@nextcloud/vue/components/NcButton'

/**
 * Mount a <form @submit.prevent> containing a single NcButton carrying the
 * given props, and return the wrapper plus a submit counter.
 *
 * @param {object} buttonProps props to pass to NcButton
 * @return {Promise<object>} wrapper, submits() accessor and the button element
 */
async function mountFormWithButton(buttonProps) {
	const submitted = []

	const Harness = defineComponent({
		methods: {
			onSubmit() {
				submitted.push(Date.now())
			},
		},
		render() {
			return h('form', { onSubmit: (e) => { e.preventDefault(); this.onSubmit() } }, [
				h(NcButton, buttonProps, { default: () => 'Go' }),
			])
		},
	})

	const wrapper = mount(Harness, { attachTo: document.body })
	await wrapper.vm.$nextTick()

	return {
		wrapper,
		button: wrapper.find('button'),
		submits: () => submitted.length,
	}
}

describe('NcButton submit wiring on @nextcloud/vue 9', () => {
	it('positive control: the harness observes a submit from a plain type=submit button', async () => {
		const submitted = []
		const Plain = defineComponent({
			render() {
				return h('form', { onSubmit: (e) => { e.preventDefault(); submitted.push(1) } }, [
					h('button', { type: 'submit' }, 'Go'),
				])
			},
		})
		const wrapper = mount(Plain, { attachTo: document.body })
		await wrapper.find('button').trigger('click')
		expect(submitted.length).toBe(1)
	})

	it('renders a real <button> element (guards the mount itself)', async () => {
		const { button } = await mountFormWithButton({ type: 'submit' })
		expect(button.exists()).toBe(true)
		expect(button.element.tagName).toBe('BUTTON')
	})

	it('THE FIX: type="submit" renders type=submit and fires the form submit handler', async () => {
		const { button, submits } = await mountFormWithButton({ type: 'submit' })

		expect(button.attributes('type')).toBe('submit')

		await button.trigger('click')
		expect(submits()).toBe(1)
	})

	it('NEGATIVE CONTROL: the v8 spelling native-type="submit" leaves type=button and fires NOTHING', async () => {
		const { button, submits } = await mountFormWithButton({ nativeType: 'submit' })

		// v9 ignores nativeType; the `type` prop defaults to "button".
		expect(button.attributes('type')).toBe('button')

		await button.trigger('click')
		expect(submits()).toBe(0)
	})

	it('documents that nativeType is not a declared prop on v9 (it falls through to the DOM)', async () => {
		const { button } = await mountFormWithButton({ nativeType: 'submit' })

		// An undeclared prop lands in the DOM as a plain attribute. Its
		// presence is the signature of the un-migrated spelling.
		expect(button.attributes('nativetype')).toBe('submit')
	})

	it('the half-migrated spelling type="primary" still submits, but loses its variant styling', async () => {
		// This is the OTHER un-migrated shape: BOTH v8 prop names. It behaves
		// differently from `native-type` alone and must not be conflated with
		// it. "primary" is not a valid native button type, and HTML's invalid
		// value default for <button type> is the Submit Button state -- so the
		// form DOES still submit here. The visible damage is cosmetic: the
		// value is consumed as the native type, so `variant` keeps its
		// "secondary" default and the button renders unstyled.
		const { button, submits } = await mountFormWithButton({ type: 'primary', nativeType: 'submit' })

		expect(button.classes().join(' ')).toContain('secondary')
		expect(button.classes().join(' ')).not.toContain('button-vue--primary')

		await button.trigger('click')
		expect(submits()).toBe(1)
	})

	it('variant is the v9 visual prop: variant="primary" styles without touching the button type', async () => {
		const { button } = await mountFormWithButton({ variant: 'primary', type: 'submit' })

		expect(button.attributes('type')).toBe('submit')
		expect(button.classes().join(' ')).toContain('primary')
	})
})
