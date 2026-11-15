<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  PreviewBucket: one bucket of a configuration preview (created, updated or
  colliding objects): a heading with the count and the type and slug of each
  entry. The host passes its BEM block for the class and styles the bucket.

  @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
-->
<template>
	<div :class="`${block}__bucket`">
		<h5>{{ title }} ({{ entries.length }})</h5>
		<ul>
			<li
				v-for="entry in entries"
				:key="`${keyPrefix}-${entry.type}-${entry.slug}`">
				<code>{{ entry.type }}</code> {{ entry.slug }}
			</li>
		</ul>
	</div>
</template>

<script>
export default {
	name: 'PreviewBucket',

	props: {
		/** BEM block of the host, used for the bucket class. */
		block: { type: String, required: true },
		/** The translated bucket heading. */
		title: { type: String, required: true },
		/** The entries: `{ type, slug }`. */
		entries: { type: Array, required: true },
		/** Prefix of the list keys. */
		keyPrefix: { type: String, required: true },
	},
}
</script>
