<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CircuitBreakerBadge — the per-Source circuit breaker state surface on the
  Source detail page (manifest `config.bodyWidgets`, component
  `CircuitBreakerBadge`).

  Rendered as a declarative body section on SourceDetail: it injects the
  loaded source object off `cnSectionContext` (provided by CnDetailPage), so
  no explicit @object props are needed. It shows the breaker state (open /
  closed) with the failure count and, when open, a live cooldown countdown,
  plus a "Reset breaker" action that hits the admin-only reset endpoint
  (`POST /api/sources/{id}/circuit-breaker/reset`, REQ-009).

  Breaker state persists on the source OR object (design.md Decision 2), so
  this reads the same object CnDetailPage already loaded — no new read path.

  @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
-->
<template>
	<div class="circuitBreaker" data-testid="circuit-breaker-badge">
		<div class="circuitBreaker__row">
			<span class="circuitBreaker__badge"
				:class="isOpen ? 'circuitBreaker__badge--open' : 'circuitBreaker__badge--closed'"
				data-testid="circuit-breaker-state">
				{{ isOpen ? t('openconnector', 'Circuit open') : t('openconnector', 'Circuit closed') }}
			</span>
			<span class="circuitBreaker__failures">
				{{ t('openconnector', 'Failures: {count}', { count: failureCount }) }}
			</span>
			<span v-if="isOpen && cooldownRemaining > 0" class="circuitBreaker__cooldown" data-testid="circuit-breaker-cooldown">
				{{ t('openconnector', 'Cooldown: {seconds}s', { seconds: cooldownRemaining }) }}
			</span>
			<NcButton v-if="isOpen"
				type="primary"
				:disabled="busy || !objectId"
				data-testid="circuit-breaker-reset"
				@click="reset">
				{{ t('openconnector', 'Reset breaker') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'CircuitBreakerBadge',

	components: { NcButton },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			busy: false,
			// Local override applied after a successful reset so the badge
			// flips to "Closed" without waiting for a full page reload.
			localState: null,
			now: Math.floor(Date.now() / 1000),
			tickTimer: null,
		}
	},

	computed: {
		/**
		 * The loaded source object, read off the injected section context.
		 * @return {object}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
		 */
		source() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return (value && value.object) || {}
		},
		/**
		 * The source's OR object id, used for the reset endpoint URL.
		 * @return {string|null}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
		 */
		objectId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return (value && value.objectId) || this.source.uuid || this.source.id || null
		},
		/**
		 * Effective breaker state (a successful local reset wins over the
		 * loaded object until the page reloads).
		 * @return {string}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
		 */
		state() {
			return this.localState || this.source.circuitBreakerState || 'closed'
		},
		isOpen() {
			return this.state === 'open'
		},
		/**
		 * The consecutive-failure count backing the badge. Reads 0 once the
		 * breaker has been manually reset locally, so the operator sees the
		 * reset take effect immediately rather than the pre-reset count the
		 * source object still carries until it is refetched.
		 *
		 * @return {number}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
		 */
		failureCount() {
			if (this.localState === 'closed') {
				return 0
			}
			return Number(this.source.circuitBreakerFailureCount || 0)
		},
		/**
		 * Seconds left in the open breaker's cooldown window (0 when elapsed).
		 * @return {number}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
		 */
		cooldownRemaining() {
			const openedAt = Number(this.source.circuitBreakerOpenedAt || 0)
			const cooldown = Number(this.source.circuitBreakerCooldownSeconds || 30)
			if (!openedAt) {
				return 0
			}
			return Math.max(0, (openedAt + cooldown) - this.now)
		},
	},

	/**
	 * Starts the one-second tick that drives the cooldown countdown. The
	 * remaining time is derived from `now` rather than decremented, so the
	 * display stays correct if the tab is backgrounded and the interval is
	 * throttled.
	 *
	 * @return {void}
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	mounted() {
		this.tickTimer = setInterval(() => {
			this.now = Math.floor(Date.now() / 1000)
		}, 1000)
	},

	// Vue 3 renamed this hook: `beforeDestroy` is not recognised and is
	// silently ignored, so the 1Hz interval above would outlive every badge
	// that ever mounted, ticking forever against a dead component's state.
	beforeUnmount() {
		clearInterval(this.tickTimer)
	},

	methods: {
		t,
		/**
		 * Reset the breaker via the admin-only endpoint (REQ-009) and flip
		 * the local state to closed on success.
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
		 */
		async reset() {
			if (!this.objectId) {
				return
			}
			this.busy = true
			try {
				const res = await axios.post(
					generateUrl(`/apps/openconnector/api/sources/${this.objectId}/circuit-breaker/reset`),
				)
				this.localState = res.data?.circuitBreakerState || 'closed'
				showSuccess(t('openconnector', 'Circuit breaker reset'))
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(t('openconnector', 'Failed to reset circuit breaker') + (detail ? `: ${detail}` : ''))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.circuitBreaker {
	padding: 8px 0;
}

.circuitBreaker__row {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
}

.circuitBreaker__badge {
	padding: 2px 12px;
	border-radius: var(--border-radius-pill);
	font-weight: 600;
	background: var(--color-background-dark);
}

.circuitBreaker__badge--open {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.circuitBreaker__badge--closed {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.circuitBreaker__failures,
.circuitBreaker__cooldown {
	color: var(--color-text-maxcontrast);
}
</style>
