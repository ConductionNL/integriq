---
kind: code
depends_on: []
---

# Proposal: gateway-a2a-agent-card

## Summary

An A2A agent can already be published behind an integriq endpoint, because the endpoint proxies any HTTP call. Its agent card still names the agent's own address, so another agent that reads the card can bypass the gateway. This change adds an endpoint target of type `a2a` that rewrites the agent card to the gateway address and applies the endpoint's authentication, limits and logging to every call.

## Why

Matrix row `integriq:acc-a2a-gateway`, "Put agent-to-agent (A2A) traffic behind the gateway, so other agents reach an agent only through it." The row was marked built on the generic proxy of `endpoint-runtime` REQ-EP-003, but `grep -riE 'a2a|agent.?card'` over `lib/` and `src/` finds nothing, so the card is not rewritten. Following decision 389 the row is set back to `specified` with this change as its plan.

This change covers one row: `integriq:acc-a2a-gateway`.
