/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * API-direct HTTP-contract assertions for the `openconnector.synchronization`
 * flow-node leaf (openconnector-flow-migration Phase 1,
 * specs/synchronization-flow-node/spec.md — REQ-SFN-001 registration,
 * REQ-SFN-004 execute-adapter).
 *
 * These are NOT UI-driving tests. The OpenRegister flow node PALETTE is not
 * exposed as an HTTP endpoint yet (only the visual builder — a WIP canvas — will
 * surface it), so "the leaf is registered" is asserted TRANSITIVELY: a flow that
 * references `openconnector.synchronization` and is run via
 * `POST /api/openregister/api/flow-runs/test` resolves the node through
 * `FlowNodeRegistry`. If the leaf were NOT registered, the run would fail at
 * dispatch with an "unknown node type" error; if it IS registered, the node's
 * `execute()` runs and (for a non-existent synchronizationId) fails instead with
 * a "synchronization not found" error — a distinguishable outcome.
 *
 * Excluded from the gate-19 UI run via the `**​/api-direct/**` testIgnore in
 * playwright.config.ts (API-direct → Newman home).
 *
 * LIVE-RUN STATUS:
 * - The endpoint-health smoke test runs on any provisioned instance.
 * - The full registration+execute assertion is `test.fixme` because it requires
 *   the Phase-1 leaf DEPLOYED to the target instance (additive files + the
 *   Application.php registration block + an apache graceful reload) and a flow
 *   OBJECT authored in the shared `flows` register referencing the node. The
 *   leaf's logic itself is fully covered by the unit suite
 *   (tests/Unit/Service/Flow/Nodes/SynchronizationNodeTest.php +
 *   tests/Unit/EventListener/RegisterFlowNodesListenerTest.php — 12 cases, green).
 *   This file documents the observable HTTP contract and provisions what it can;
 *   the coordinated deploy to the shared instance is why the live assertion is
 *   deferred rather than run here (a parallel session was mid-e2e on 8080).
 */

import { test, expect } from '@playwright/test'

const OR_API = '/index.php/apps/openregister/api'

test.describe('openconnector.synchronization flow-node leaf — HTTP contract', () => {
	// The flow engine's run-test endpoint stays reachable (never 500) — a smoke
	// assertion safe on any instance that has the OpenRegister flow engine.
	// A bogus flowId is rejected with a client error (404/400/422), not a 500.
	test('flow run-test endpoint is routable and validates flowId (never 500)', async ({ request }) => {
		const resp = await request.post(`${OR_API}/flow-runs/test`, {
			headers: { 'OCS-APIRequest': 'true' },
			data: { flowId: `does-not-exist-${Date.now()}` },
			failOnStatusCode: false,
		})
		expect(resp.status()).not.toBe(500)
		expect(resp.status()).toBeLessThan(500)
	})

	// CONTRACT (documented; live-run deferred — see file header):
	//
	// GIVEN the Phase-1 leaf is deployed and a flow object in the `flows` register
	//   has a single node `openconnector.synchronization` on its edge, configured
	//   { synchronizationId: '<uuid>' },
	// WHEN POST /api/flow-runs/test runs that flow,
	// THEN the run resolves the node through FlowNodeRegistry (it is NOT reported
	//   as an "unknown node type"): registration (REQ-SFN-001) is proven.
	// AND with a non-existent synchronizationId the node's execute() runs and the
	//   run fails with a "synchronization not found" error (REQ-SFN-004: the leaf
	//   adapts to SynchronizationService and lets the exception propagate so the
	//   engine applies the edge's onError policy) — distinguishable from the
	//   "unknown node type" dispatch failure a missing registration would give.
	// NEGATIVE CONTROL: an otherwise identical flow whose node id is bogus
	//   (e.g. `openconnector.does-not-exist`) fails with "unknown node type",
	//   confirming the assertion above distinguishes registered from not.
	test.fixme(
		true,
		'coordinated deploy of the Phase-1 leaf to the shared instance + a provisioned flow object required — see file header; unit-covered meanwhile',
	)
})
