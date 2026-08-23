<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  JavascriptForm — single-textarea code editor for the `javascript`
  action. Stored as a bare string at `configuration.javascript`.
  EndpointService::processJavaScriptRule is currently a stub but we
  preserve the legacy modal's shape so it round-trips cleanly.
-->
<template>
	<div class="action-form">
		<label class="action-form__label" :for="'rule-action-js-' + uid">
			{{ t('integriq', 'JavaScript code') }}
		</label>
		<textarea
			:id="'rule-action-js-' + uid"
			class="action-form__textarea action-form__textarea--code"
			:value="code"
			spellcheck="false"
			rows="10"
			:placeholder="t('integriq', 'Enter your JavaScript code here...')"
			@input="(event) => $emit('update:code', event.target.value)" />
		<span class="action-form__helper">
			{{
				t(
					'integriq',
					'Sandboxed script that runs against the request data. Stored as configuration.javascript.',
				)
			}}
		</span>
	</div>
</template>

<script>
let uidCounter = 0
export default {
	name: 'JavascriptForm',
	props: {
		code: { type: String, default: '' },
	},

	data() {
		return { uid: ++uidCounter }
	},
}
</script>

<style scoped>
.action-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.action-form__label {
	font-weight: bold;
}

.action-form__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.action-form__textarea {
	width: 100%;
	padding: 8px;
	font-family: var(--font-face, sans-serif);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.action-form__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}
</style>
