# Cloudevents

We facilitate subscriptions on a pub/sub model. This is based on [CloudEvents](https://cloudevents.io/) but also supports the [NL GOV profile for CloudEvents](https://www.logius.nl/domeinen/gegevensuitwisseling/nl-gov-profile-cloudevents). More documentation can be found [here](https://gitdocumentatie.logius.nl/publicatie/notificatieservices/CloudEvents-NL/).

# Event System

## Event Subscriptions

The OpenConnector platform implements the [CloudEvents Subscription API specification](https://github.com/cloudevents/spec/blob/main/subscriptions/spec.md) to manage event subscriptions. This allows consumers to subscribe to specific events and receive them through various delivery mechanisms.

### Subscription Styles

The platform supports two subscription styles:

- **Push**: Events are actively sent to the subscriber's endpoint (sink)
- **Pull**: Subscribers fetch events from the platform

### Subscription Properties

Each subscription contains the following properties:

- `id`: Unique identifier for the subscription
- `source`: URI identifying where events originate
- `types`: Array of CloudEvent type values to subscribe to
- `config`: Subscription-specific configuration
- `filters`: Array of filter expressions for event matching
- `sink`: URI where events should be delivered
- `protocol`: Delivery protocol (HTTP, MQTT, AMQP, etc.)
- `protocolSettings`: Protocol-specific settings
- `style`: Delivery style ('push' or 'pull')
- `status`: Subscription status
- `userId`: Owner of the subscription

### Filter Dialects

The platform supports the following filter dialects as defined in the CloudEvents specification:

- `exact`: Exact matching of attribute values
- `prefix`: Prefix matching of attribute values
- `suffix`: Suffix matching of attribute values
- `all`: Logical AND of multiple filters
- `any`: Logical OR of multiple filters
- `not`: Logical NOT of a filter

### Example Subscription
