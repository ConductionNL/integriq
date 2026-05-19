# Events and Webhooks

## Overview

OpenConnector implements event-driven integration using the **NL GOV CloudEvents** specification. It can emit events when internal state changes, subscribe to external event streams, and process incoming webhook payloads via configured Consumers. This enables real-time, loosely coupled data flows between systems without relying solely on scheduled synchronizations.

## Core Concepts

### Events

An **Event** defines a CloudEvent type that OpenConnector can emit or receive. Each event has:

| Field | Description |
|-------|-------------|
| `name` | Human-readable name |
| `slug` | URL-friendly identifier |
| `type` | CloudEvents `type` field (e.g. `nl.vng.zgw.zaken.zaak.created`) |
| `source` | CloudEvents `source` URI (e.g. `https://openconnector.yourdomain.nl`) |
| `schema` | Optional JSON Schema reference for the event data payload |
| `isEnabled` | Whether the event is active |

### EventSubscriptions

An **EventSubscription** subscribes to a specific event type and routes matching events to a handler. Subscription matching is based on the CloudEvents `type` field. Optional filter expressions (JSON Logic or attribute matching) narrow which events trigger the subscription.

| Field | Description |
|-------|-------------|
| `eventType` | CloudEvents type to subscribe to |
| `endpoint` | Handler endpoint URL or internal reference |
| `method` | HTTP method for handler invocation |
| `status` | `active` or `paused` |
| `filters` | Optional attribute filters |

### Consumers

A **Consumer** is a configured handler for incoming webhook payloads from external systems. Consumers expose an endpoint path at OpenConnector, receive the incoming payload, apply an optional mapping, and forward the result to a configured target source or OpenRegister schema.

| Field | Description |
|-------|-------------|
| `name` | Human-readable name |
| `endpoint` | Exposed webhook path |
| `mappingId` | Optional mapping to apply to incoming payload |
| `targetType` | `source` or `register/schema` |
| `targetId` | Target source or register ID |
| `isEnabled` | Whether the consumer is active |

## NL GOV CloudEvents

All events emitted by OpenConnector conform to the [NL GOV CloudEvents profile](https://logius.nl/diensten/cloudevents):

```json
{
  "specversion": "1.0",
  "type": "nl.vng.zgw.zaken.zaak.created",
  "source": "https://openconnector.yourdomain.nl",
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "time": "2024-06-15T10:30:00Z",
  "datacontenttype": "application/json",
  "data": {
    "zaakUrl": "https://zaakregister.yourdomain.nl/api/v1/zaken/abc123"
  }
}
```

## Event Processing Flow

### Outbound (Emission)

```
Internal state change (e.g. synchronization creates an object)
        |
        v
EventService.processEvent(event)
        |
        v
Find all active subscriptions matching the event type
        |
        v
For each matching subscription:
  - Create EventMessage
  - Attempt immediate delivery (push subscription)
  - Or queue for polling (pull subscription)
```

### Inbound (Consumption)

```
External system sends POST to /api/endpoint/{consumer-path}
        |
        v
Consumer matched by path
        |
        v
Payload validated (optional schema validation)
        |
        v
Mapping applied (optional)
        |
        v
Result written to target (source or OpenRegister)
```

## Delivery Guarantees

EventMessages are persisted before delivery is attempted. Failed deliveries are retried according to subscription configuration. The message status (`pending`, `delivered`, `failed`) is tracked per message.

## GEMMA Role

OpenConnector fulfils the **Notificatierouteringcomponent** role in the GEMMA architecture through this events subsystem — routing notifications between components in the Common Ground ecosystem.

## Implementation

- `lib/Service/EventService.php` — Event processing, subscription matching, message creation
- `lib/Controller/EventsController.php` — Event and subscription CRUD API
- `lib/Controller/ConsumersController.php` — Consumer CRUD API
- `lib/Db/Event.php` — Event entity
- `lib/Db/EventSubscription.php` — Subscription entity
- `lib/Db/EventMessage.php` — Message delivery tracking entity
