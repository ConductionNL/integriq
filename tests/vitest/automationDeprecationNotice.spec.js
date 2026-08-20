// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contract guard for the automation deprecation notice
 * (flow-native-synchronization task 3.2).
 *
 * Two things about this banner can regress silently:
 *
 *  1. **The copy can start lying.** The notice exists to say that the
 *     surface is being replaced while the engine underneath is unchanged
 *     and existing configurations keep running. If someone trims it to
 *     "This page is deprecated", an operator reads it as "this is broken"
 *     and files a ticket. So the assertions here are about MEANING —
 *     the reassurance sentence and the "no automatic conversion yet"
 *     sentence — not about the mere presence of a card.
 *
 *  2. **The action can start claiming a conversion that cannot happen.**
 *     Task 3.1 (Synchronization entity → generated flow) is not built, so
 *     there is nothing per-object to open. The button must navigate to the
 *     Flows INDEX, by ROUTE NAME. A hardcoded `/apps/openconnector/flows`
 *     href would leave the SPA on this stack (the router history base is
 *     `generateUrl('/apps/openconnector')`, i.e. an `/index.php/...`
 *     prefix), and a button wired to nothing is worse than no button — so
 *     the push payload is asserted exactly, not just "push was called".
 *
 * The route name is checked against src/manifest.json rather than against
 * a literal, because route names ARE manifest page ids (`routesFromManifest`
 * in src/main.js maps `name: page.id`). Renaming the Flows page in the
 * manifest without updating the component fails here instead of shipping a
 * button that silently resolves to nothing.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AutomationDeprecationNotice, {
	FLOWS_ROUTE_NAME,
} from '@/components/AutomationDeprecationNotice.vue'
import manifest from '@/manifest.json'

/** Deterministic stand-in for the app's global `t` (see src/main.js). */
const t = (app, text) => text

/**
 * Mount the notice with a spy router.
 *
 * @return {{wrapper: object, push: Function}} the mounted wrapper and the
 *   `$router.push` spy the component is expected to call.
 */
function mountNotice() {
	const push = vi.fn()
	const wrapper = mount(AutomationDeprecationNotice, {
		global: {
			mocks: { t, $router: { push } },
		},
	})
	return { wrapper, push }
}

describe('AutomationDeprecationNotice', () => {
	it('renders as a non-blocking warning note, not an error', () => {
		const { wrapper } = mountNotice()
		const notice = wrapper.find('[data-testid="automation-deprecation-notice"]')

		expect(notice.exists()).toBe(true)
		// NcNoteCard renders its `type` as a modifier class. "error" here would
		// tell the operator something is broken, which is the opposite of what
		// this notice means.
		expect(notice.classes().join(' ')).toContain('warning')
		expect(notice.classes().join(' ')).not.toContain('error')
	})

	it('names the four legacy surfaces and where they are going', () => {
		const { wrapper } = mountNotice()
		const text = wrapper.text()

		for (const surface of ['Jobs', 'Rules', 'Mappings', 'Synchronizations']) {
			expect(text).toContain(surface)
		}
		expect(text).toContain('Flow editor')
	})

	it('states that nothing is switched off and existing configurations keep running', () => {
		const { wrapper } = mountNotice()
		const text = wrapper.text()

		expect(text).toContain('Nothing has been switched off')
		expect(text).toContain('engine underneath is unchanged')
		expect(text).toContain('keeps running')
	})

	it('does not claim a conversion that task 3.1 has not built yet', () => {
		const { wrapper } = mountNotice()
		const text = wrapper.text()

		// The honest statement must be present...
		expect(text).toContain('no automatic conversion yet')
		// ...and the button must not promise one. "Convert"/"Migrate" here
		// would be a claim the code cannot honour until task 3.1 lands.
		const label = wrapper
			.find('[data-testid="automation-deprecation-goto-flows"]')
			.text()
		expect(label).toBe('Go to Flows')
		expect(label.toLowerCase()).not.toContain('convert')
		expect(label.toLowerCase()).not.toContain('migrate')
	})

	it('navigates to the Flows index by route name when the action is clicked', async () => {
		const { wrapper, push } = mountNotice()

		expect(push).not.toHaveBeenCalled()

		await wrapper
			.find('[data-testid="automation-deprecation-goto-flows"]')
			.trigger('click')

		expect(push).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith({ name: FLOWS_ROUTE_NAME })
	})

	it('targets a route name that a manifest page actually declares', () => {
		const flowsPage = manifest.pages.find((page) => page.id === FLOWS_ROUTE_NAME)

		expect(flowsPage).toBeDefined()
		expect(flowsPage.route).toBe('/flows')
		// A parameterised route could not be pushed by name alone.
		expect(flowsPage.route).not.toContain(':')
	})

	it('is mounted on all four legacy index pages via the below-header slot', () => {
		const legacyIndexPages = ['Jobs', 'Rules', 'Mappings', 'Synchronizations']

		for (const id of legacyIndexPages) {
			const page = manifest.pages.find((entry) => entry.id === id)
			expect(page, `manifest page ${id}`).toBeDefined()
			expect(page.slots?.['below-header'], `slots on ${id}`).toBe(
				'AutomationDeprecationNotice',
			)
		}
	})
})
