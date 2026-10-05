# Design: ask the opt-out list before every send

The fleet contract, the full sender table, the category list and the fail mode live in hydra's `openspec/changes/opt-out-before-send/design.md` (ConductionNL/hydra#739). This file covers what integriq builds. Lines are at `development` `c52ee137`.

## 1. One decision function

`OptOutRegistry::decide(address, category, caseRef)` (`lib/Outbound/Identity/OptOutRegistry.php:115`) stays the one place a decision is made. It grows into a batch call:

```php
decideMany(string $channel, string $category, bool $requiresConsent, list<array{address:string, caseRef?:string, listRef?:string, contactRef?:string}> $recipients, string $sourceApp, string $correlationId): array<string, Decision>
```

The single `decide()` stays as a wrapper over a batch of one. Everything that asks goes through `decideMany()`: integriq's own senders through DI, sibling apps through the event listener.

The read stays on the mapper: `OptOutMapper::findForAddress()` (`lib/Db/OptOutMapper.php:73`) gains `findForAddresses(list<string>)`. It reads `address IN (...)` on the existing index `integriq_optout_addr` (`lib/Migration/Version2Date20261005100000.php:75`). No RBAC applies, so #2114's fail-open does not reach this read. The docblock at `OptOutRegistry.php:15-20` already says so.

### The rules, in order

1. Normalise the address. Email: trim and lowercase, as `add()` does today (`OptOutRegistry.php:156`). Phone: `PhoneNumberValidator::toE164()` (`lib/Service/Sms/PhoneNumberValidator.php:81`). Digital post: see section 6. A `reply` with `inReplyTo` is allowed here, before any opt-out is read, and carries no link. An address that does not normalise gets code `invalid-address` and `send: false`.
2. If the category is exempt: `send: true`. When any opt-out matched, `overridden: true`, code `exempt-override`, and a log entry.
3. If any `opted-out` row matches (instance; or channel = this channel; or case = this caseRef; or list = this listRef): `send: false`, code `opted-out`.
4. If `requiresConsent`: an `opted-in` row must match on channel, or on list when a listRef is given. A channel row does not open a list, and a list row does not open a channel, as pipelinq has it now (`pipelinq/lib/Service/ComplianceService.php:410-428`). The lawful basis must permit the send: `imported` never does, and `soft-opt-in` needs `evidence.objectionOffered`. Otherwise code `no-consent`.
5. Otherwise `send: true`, code `allowed`.

A `contactRef` match counts the same as an address match. So a pipelinq contact whose address changed is still honoured.

### The exempt set

`DEFAULT_PROTECTED` (`OptOutRegistry.php:87`) becomes a fixed floor: `besluit`, `statutory`, `account`, `security`. `ontvangstbevestiging` and `invordering` map to `statutory`. `protectedCategories()` (`:215-231`) stops returning whatever the config says. The config key `outbound.protected_categories` (`:80`) can only add aliases of floor categories. A value that tries anything else is ignored with a warning (ADR-102).

## 2. The events

Both live in `lib/Event/`. Both follow the result-slot shape of `DigitalPostSendRequestedEvent` (`lib/Event/DigitalPostSendRequestedEvent.php:169-219`: `setHandled`, `isHandled`, `setRefusal`, `getRefusal`).

- `OutboundSendDecisionRequestedEvent`: fields and result as in hydra's design section 2. The listener `OutboundSendDecisionRequestedListener` calls `decideMany()`, writes each decision into the slot, and sets handled. It catches nothing it cannot answer: an exception leaves the event unhandled, which siblings read as absent.
- `OptOutChangeRequestedEvent`: the listener `OptOutChangeRequestedListener` calls a new `OptOutRegistry::record()`. That method upserts on the dedupe key and writes a log entry. A `legacyRef` already present means the write is skipped and the existing id returned.

Both listeners are registered in `lib/AppInfo/Application.php`, next to the existing `addServiceListener` lines (`:345-346`).

## 3. integriq's own senders

| Sender | Where the ask goes | Category |
|---|---|---|
| `SmsDispatchService::sendMessage` (`lib/Service/SmsDispatchService.php:136`) | after E.164 normalisation (`:147`), before the provider send (`:200`) | `options['category']`, set from a new optional field on POST `/api/notifynl/messages` (`NotifyNlController::send`, action `sms.send` at `lib/Controller/NotifyNlController.php:104`). Default `service`. |
| `IntakeReplyService::reply` (`lib/Intake/IntakeReplyService.php:67`) | before `$adapter->reply()` (`:82`) | `reply`, with the intake message uuid as `inReplyTo`. An opt-out does not stop it. |
| `DigitalPostService::handleSendRequest` (`lib/Service/DigitalPost/DigitalPostService.php:77`) | after the source and provider checks, before the provider send (`:240`) | the event's new optional `category`. Default `service`. |
| `EventService::ingestDeliveryRequest` (`lib/Service/EventService.php:2870`) | only when the payload names a personal `recipient` | the payload's `category`. Default `service`. |

On a refusal:

- SMS: `SmsProviderException` with code `opted-out`. The controller answers 409 with `error: opted-out`.
- Intake reply: the `ReplyResult` carries the refusal. The handler sees why.
- Digital post: `setHandled(true)` and `setRefusal(reason, 'opted-out')`. dossiq already reads that (`dossiq/lib/Service/BerichtenboxAdapter/IntegriqAdapter.php:245-246`).
- Delivery: not routed. The outbound log row says why.

Every send that goes out composes through `MessageComposer::compose()` (`lib/Outbound/Identity/MessageComposer.php:59`), so the link is added in one place. Each one also starts an outbound log row through `MessageRecorder::start()` (`lib/Outbound/MessageRecorder.php:134`). Today only `ForwardService` does.

## 4. The table and the log

A migration adds to `integriq_opt_outs`:

| Column | Type | Default |
|---|---|---|
| `state` | string(16) | `opted-out` |
| `channel` | string(16) | `''` (all channels) |
| `purpose` | string(32) | `''` |
| `list_ref` | string(255) | `''` |
| `contact_ref` | string(255) | `''` |
| `lawful_basis` | string(32) | `''` |
| `evidence` | text (JSON) | null |
| `withdrawn_at` | bigint | null |
| `source_app` | string(64) | `''` |
| `updated_at` | bigint | 0 |

`scope` gains `channel` and `list` beside `instance` and `case`. The dedupe key is `sha256(address \n scope \n caseRef)` today (`lib/Db/OptOut.php:132-133`). It widens to address, scope, ref and channel, and appends the channel only when it is not empty. So an existing row, which has no channel, keeps the key it has. The ref in the key is the case ref for a case scope and the list ref for a list scope. An index on `contact_ref` is added.

A second migration adds `integriq_opt_out_log`: `id`, `at`, `kind` (`change`, `suppressed`, `override`, `allowed-count`), `address`, `category`, `channel`, `source_app`, `correlation_id`, `detail` (JSON). Append-only. No update path.

## 5. The link

- `UnsubscribeTokenService::mint()` (`lib/Outbound/Identity/UnsubscribeTokenService.php:128`) gains a version 3 claim set: `a` address, `s` scope, `ch` channel, `r` ref, `e` expiry. Version 1 and 2 keep verifying as case stops (`inspect()`, `:169`).
- `linkFor()` (`:322`) returns a structure, not a string: `{url, oneClickUrl, smsText, headers}`. It still returns null for an exempt category (`:323-325`).
- GET `/unsubscribe/{token}` (`appinfo/routes.php:120`) stops writing. It shows what will stop and a button. POST `/unsubscribe/{token}` writes and answers 200 without a redirect (RFC 8058). Same `#[PublicPage]`, `#[NoCSRFRequired]` and `#[AnonRateLimit]` as the GET (`lib/Controller/SenderIdentityController.php:225-227`).
- GET `/u/{id}` resolves a 10-character short id to a version 3 token and shows the same page. A small table `integriq_unsubscribe_short` maps id to token and expiry. `smsText` is "Stop? Antwoord STOP" when the source handles inbound keywords, otherwise `Stop: <host>/u/<id>`.
- The page offers two choices where they apply: stop this case or list, or stop everything that is not statutory.

## 6. Digital post recipients are a BSN

`DigitalPostSendRequestedEvent` names the recipient as "for example a BSN" (`lib/Event/DigitalPostSendRequestedEvent.php:70`). An opt-out keyed on a plain BSN puts a BSN in a table that has no reason to hold one. So the address for digital post is `bsn:` plus an HMAC-SHA256 of the BSN under an instance secret. The lookup is an equality match, so the hash works. The unsubscribe token carries the same hashed value, never the BSN. Ruben approved this on 2026-10-05.

## 7. Performance

- `decideMany()` reads in chunks of 500: one query per chunk.
- No caching across requests.
- The log writes one row per suppression or override and one count row per chunk.
- Measure on the development instance: 500 recipients with 5 opt-outs, under 50 ms for the decision. Record the number in the task.

## 8. Decisions (approved by Ruben 2026-10-05)

The fleet decisions are in hydra's design section 12. The ones that shape this change:

- **Fail closed.** The listener leaves the event unhandled when it cannot answer. Siblings then refuse non-exempt messages.
- **Replies pass an opt-out.** `decideMany()` gives `send: true` for `reply` when `inReplyTo` is set, before the opt-out rule. The intake reply sets it to the intake message uuid. Without `inReplyTo`, `reply` reads as `service`.
- **The table keeps its name**, `integriq_opt_outs`.
- **Category over channel.** A digital post `besluit` is sent. A digital post `case-update` respects opt-outs.
- **Erasure keeps the opt-out.** `OptOutChangeRequestedEvent` accepts state `erase-contact`: it clears `contact_ref` and `evidence` on the rows for that `contactRef`, and keeps the address and state.
- **The short-link table is built** (section 5).
- **The log is kept 7 years.** A daily `TimedJob` deletes `integriq_opt_out_log` entries older than that.
- **BSNs are hashed** (section 6).
