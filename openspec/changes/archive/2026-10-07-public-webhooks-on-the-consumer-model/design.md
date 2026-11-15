# Design: public-webhooks-on-the-consumer-model

## The shape

```
partner --signed POST--> Controller
                          |  WebhookGate::identify(profile, rawBody, request)
                          |    WebhookConnection::authenticate()
                          |      DsoConnection::findConsumers(authorizationType)   engine read, _rbac false
                          |      WebhookSignatureService::verify(consumer trust)    401 on failure
                          |      IUserManager: account exists and is enabled        503 otherwise
                          |      DsoAccountRights::missing(schema)                  503 otherwise
                          |  WebhookGate::deliver(identity, work)
                          |    ObjectService::runAs(account, work)                  every write as the account
                          |  any throw -> WebhookGate::notStored()                  503 + alert
```

## Decisions

- **A profile, not a class per webhook.** `WebhookProfile` names the consumer type, the alert channel, the label, the schema the account writes and the rights it needs. `WebhookProfiles` lists them. Adding a webhook is one profile.
- **Open Formulieren delegates.** `OpenFormulierenConnection` keeps its public API and its profile, and calls `WebhookConnection`. Its tests stay green unchanged. The OF migration repair delegates to the shared `WebhookTrustMigrator` too.
- **One consumer per intake channel.** Each channel had its own source and secret, so each gets its own consumer (`intake-channel-<channelId>`). The verdicts webhook is the channel `verdicts`.
- **A refused write answers 503.** The controllers used to catch every error after the signature check and answer 200. That hid lost deliveries. Translation errors the services already swallow still answer 200, as before.
- **Admin config stays an engine read.** The consumer read is `_rbac: false`, as in DSO and OF. The StUF-ZKN inbound leg reads its organisation codes and target register from the source; that read is an engine read now, because the account cannot read `source`. No admin config is ever written by a delivery.
- **No groups invented.** The bridge schemas have no authorization block, so OpenRegister lets any account write them. `verdict` and `intake_message` are admin-only for create. Giving them an intake group is a decision like the BSN one, and is left open.

## Contract gaps

The same two as the DSO change: `ObjectService::runAs()` and `PermissionHandler::hasPermission()`. No new OpenRegister surface.
