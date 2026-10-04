// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator registering a tool at the vendor needs six values from
 * this instance: issuer, client id, deployment ids, authorization URL, token
 * URL and key set URL (connectors-lti-platform-launch Task 4, REQ-LTIL-004).
 * Until now no page showed a tool registration at all, so these tests mount
 * the real panel with the section context CnDetailPage provides, read the
 * real manifest fragment, and check the register schema holds redirectUris.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LtiPlatformDetails from '@/components/LtiPlatformDetails.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'ariaLabel'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{
						'aria-label': this.ariaLabel,
						onClick: () => this.$emit('click'),
					},
					this.$slots.default?.(),
				)
			},
		}),
	}
})

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const DETAILS = {
	issuer: 'https://nc.example',
	clientId: 'tool-client',
	deploymentIds: ['dep-a', 'dep-b'],
	authorizationUrl:
		'https://nc.example/index.php/apps/integriq/api/lti/platform/authorize',
	tokenUrl: 'https://nc.example/index.php/apps/integriq/api/lti/token',
	keySetUrl:
		'https://nc.example/index.php/apps/integriq/.well-known/lti/lti_tool/tool-1/jwks.json',
}

/**
 * Mount the panel over one loaded tool registration.
 *
 * @return {object} the wrapper
 */
function mountForTool() {
	return mount(LtiPlatformDetails, {
		global: {
			provide: {
				cnSectionContext: {
					value: {
						objectId: 'tool-1',
						object: { clientId: 'tool-client' },
					},
				},
			},
		},
	})
}

describe('LtiPlatformDetails panel', () => {
	let writeText

	beforeEach(() => {
		get.mockReset()
		writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
	})

	it("asks this instance for the tool's platform details", async () => {
		get.mockResolvedValue({ data: DETAILS })
		mountForTool()
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toContain(
			'/apps/integriq/api/lti/tools/tool-1/platform-details',
		)
	})

	it('shows the six values, each with a copy action', async () => {
		get.mockResolvedValue({ data: DETAILS })
		const wrapper = mountForTool()
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="lti-platform-detail"]')
		expect(rows.map((row) => row.attributes('data-key'))).toEqual([
			'issuer',
			'clientId',
			'deploymentIds',
			'authorizationUrl',
			'tokenUrl',
			'keySetUrl',
		])
		for (const row of rows) {
			expect(row.find('button').exists()).toBe(true)
		}
		expect(wrapper.text()).toContain(DETAILS.tokenUrl)
		expect(wrapper.text()).toContain('dep-a')
		expect(wrapper.text()).toContain('dep-b')
	})

	it('copies the exact value of a row', async () => {
		get.mockResolvedValue({ data: DETAILS })
		const wrapper = mountForTool()
		await flushPromises()

		const keySet = wrapper.find('[data-key="keySetUrl"]')
		await keySet.find('button').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith(DETAILS.keySetUrl)
	})

	it('copies the deployment ids one per line', async () => {
		get.mockResolvedValue({ data: DETAILS })
		const wrapper = mountForTool()
		await flushPromises()

		await wrapper
			.find('[data-key="deploymentIds"]')
			.find('button')
			.trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith('dep-a\ndep-b')
	})

	it('says so when the tool has no deployment yet', async () => {
		get.mockResolvedValue({ data: { ...DETAILS, deploymentIds: [] } })
		const wrapper = mountForTool()
		await flushPromises()

		expect(wrapper.find('[data-key="deploymentIds"]').text()).toContain(
			'No deployment yet',
		)
	})

	it('shows the error when the details cannot be read', async () => {
		get.mockRejectedValue({ response: { data: { error: 'Tool not found' } } })
		const wrapper = mountForTool()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Tool not found')
		expect(wrapper.findAll('[data-testid="lti-platform-detail"]')).toHaveLength(
			0,
		)
	})
})

describe('LTI tools manifest fragment', () => {
	const merged = buildManifest(
		read('src/manifest.json'),
		[read('src/manifest.d/lti-tools.json')],
		read('src/menu-layout.json'),
	)
	const index = merged.pages.find((page) => page.id === 'LtiTools')
	const detail = merged.pages.find((page) => page.id === 'LtiToolDetail')

	it('adds an admin index and a detail page over lti_tool', () => {
		expect(index.config.schema).toBe('lti_tool')
		expect(index.permission).toBe('admin')
		expect(detail.type).toBe('detail')
		expect(detail.config.schema).toBe('lti_tool')
		expect(detail.permission).toBe('admin')
	})

	it('mounts the platform details panel on the detail page', () => {
		const components = (detail.config.bodyWidgets || []).map((w) => w.component)
		expect(components).toContain('LtiPlatformDetails')
	})

	it('registers the panel component', () => {
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(
			/import LtiPlatformDetails from '\.\/components\/LtiPlatformDetails\.vue'/,
		)
		expect(registry).toMatch(/^\s+LtiPlatformDetails,$/m)
	})

	it('never puts key material in a column or on the data panel', () => {
		const columns = index.config.columns.map((c) =>
			typeof c === 'string' ? c : c.key,
		)
		expect(columns).not.toContain('signingKeys')
		const data = (detail.config.widgets || []).find((w) => w.type === 'data')
		expect(data.content.exclude).toContain('signingKeys')
	})
})

describe('lti_tool schema', () => {
	for (const file of [
		'lib/Settings/integriq_register.json',
		'lib/Settings/integriq_mock_register.json',
	]) {
		it(`${file} holds a list of redirect URIs at 1.3.0`, () => {
			const tool = read(file).components.schemas.lti_tool
			expect(tool.version).toBe('1.3.0')
			expect(tool.properties.redirectUris.type).toBe('array')
			expect(tool.properties.redirectUris.items.type).toBe('string')
		})
	}
})
