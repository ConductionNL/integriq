# endpoint-runtime Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-a2a-agent-card

## Purpose

Other agents reach an A2A agent only through the gateway. Row `integriq:acc-a2a-gateway`.

## ADDED Requirements

### Requirement: An A2A agent's card points at the gateway (REQ-A2A-001)

Integriq MUST offer an endpoint `targetType` of `a2a` that proxies to a source holding an A2A agent. When a caller fetches the agent card through the endpoint, integriq MUST replace every address in it that points at the agent with the endpoint's public address. Every call to the agent through the endpoint MUST pass the endpoint's consumer authentication, rate limit and call logging.

#### Scenario: another agent reads the card and stays behind the gateway
- GIVEN an agent published through an `a2a` endpoint
- WHEN another agent fetches `/.well-known/agent-card.json` through the endpoint
- THEN the card's `url` is the endpoint's address, not the agent's own

#### Scenario: a call without a key is refused
- GIVEN an `a2a` endpoint with an API key rule
- WHEN an agent calls it without a key
- THEN the call is refused with 401 and never reaches the agent
