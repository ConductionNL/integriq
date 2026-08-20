<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  AutomationDeprecationNotice — the page-level notice shown on the four
  legacy automation surfaces (Jobs, Rules, Mappings, Synchronizations)
  while they are being folded into the Flow editor.

  Mounted two ways, both as page-level context rather than an error:
    - the four `type: index` pages wire it into CnIndexPage's
      `below-header` slot via the manifest `pages[].slots` map, so it sits
      under the page title and above the actions bar;
    - the three bespoke detail pages render it as the first element of
      their CnDetailPage body.

  ## What it must NOT claim

  This is task 3.2 of `flow-native-synchronization`. Task 3.1 — the
  generator that renders a Synchronization entity into a real generated
  flow — is NOT built yet, so at this point there is no per-object flow to
  open. The action therefore navigates to the Flows INDEX and is labelled
  "Go to Flows"; it deliberately does not offer, imply or perform a
  conversion. Once 3.1 lands, this component is where the affordance
  becomes a genuine per-object "Open as flow" that resolves the generated
  flow for the row/object in view.

  Nothing is removed by this change. Page removal is task 3.4 and is gated
  on the benchmark in task 2.2 plus every live synchronization having a
  reviewed generated flow.

  @spec openspec/changes/flow-native-synchronization/design.md
-->
<template>
	<NcNoteCard type="warning" data-testid="automation-deprecation-notice">
		<p class="automationDeprecation__lead">
			{{
				t(
					'openconnector',
					'Jobs, Rules, Mappings and Synchronizations are moving into the Flow editor.',
				)
			}}
		</p>
		<p>
			{{
				t(
					'openconnector',
					'Nothing has been switched off. The engine underneath is unchanged, this page still works, and every configuration you already have keeps running exactly as it does today.',
				)
			}}
		</p>
		<p>
			{{
				t(
					'openconnector',
					'There is no automatic conversion yet, so nothing here is turned into a flow for you. Open the Flows overview to see the flows that already exist.',
				)
			}}
		</p>
		<NcButton
			variant="secondary"
			data-testid="automation-deprecation-goto-flows"
			@click="goToFlows">
			<template #icon>
				<SitemapOutlineIcon :size="20" />
			</template>
			{{ t('openconnector', 'Go to Flows') }}
		</NcButton>
	</NcNoteCard>
</template>

<script>
// Per-component entry points rather than the `@nextcloud/vue` barrel. The
// barrel's module graph reaches NcRichContenteditable / NcDateTimePickerNative,
// whose module-scope initialisation calls into @nextcloud/l10n and
// @nextcloud/router at IMPORT time — so importing it drags a browser runtime
// into a unit test that only wants two presentational components. The exports
// map publishes `./components/*`, so this is a supported entry point, not a
// reach into dist internals.
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'

/**
 * vue-router route name of the Flows index page.
 *
 * Route names ARE manifest page ids (`routesFromManifest` in src/main.js
 * maps `name: page.id`), so this is the `Flows` entry of src/manifest.json.
 * Navigating by name rather than by a literal `/apps/openconnector/flows`
 * URL is mandatory here: the router history base is
 * `generateUrl('/apps/openconnector')`, which on this stack resolves to an
 * `/index.php/...` prefix, so a hardcoded path leaves the SPA.
 */
export const FLOWS_ROUTE_NAME = 'Flows'

export default {
	name: 'AutomationDeprecationNotice',

	components: {
		NcButton,
		NcNoteCard,
		SitemapOutlineIcon,
	},

	methods: {
		/**
		 * Navigate to the Flows index.
		 *
		 * Deliberately NOT a conversion: task 3.1 (Synchronization entity →
		 * generated flow) has not been implemented, so there is no generated
		 * flow to open for the object in view. When 3.1 lands, this method
		 * becomes the per-object "open as flow" — resolving the generated
		 * flow for the current object and pushing `FlowDetail` instead.
		 *
		 * The router is optional-chained so the component still renders (and
		 * the notice still reads) if it is ever mounted outside the app
		 * router — a notice that renders without its button working is
		 * recoverable; a page that fails to render is not.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/flow-native-synchronization/design.md
		 */
		goToFlows() {
			this.$router?.push({ name: FLOWS_ROUTE_NAME })
		},
	},
}
</script>

<style scoped>
.automationDeprecation__lead {
	font-weight: 600;
}

/* NcNoteCard stacks its slot content tightly; give the paragraphs and the
   action a readable rhythm without changing the card's own chrome. */
:deep(p) {
	margin: 0 0 8px 0;
}

:deep(p:last-of-type) {
	margin-bottom: 12px;
}
</style>
