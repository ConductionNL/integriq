# Design: messaging-sms-providers-and-notifynl-mail

Kind: code. Size M. Read at `development` 92f282bc.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Seam | `lib/Service/Sms/SmsProviderInterface.php:39` | two implementations, log and NotifyNL |
| Dispatcher | `lib/Service/SmsDispatchService.php:136` `sendMessage()`, :252 `pollStatus()`, :349 `resolveProvider()`, `resolveActiveSource()` | first enabled `sms` source, unknown provider runs log |
| Routes | `appinfo/routes.php:165-167` | `notifyNl#send`, `#status`, `#inbound` |
| Controller | `lib/Controller/NotifyNlController.php:97` `send()` (action `sms.send`), inbound at :208 | signed callback over `configuration.webhookSignature` |
| NotifyNL | `lib/Service/Sms/RestNotifyNlProvider.php:209` `send()`, :260 `fetchStatus()`, :354 `dispatch()`, :408 JWT | SMS only |
| Record | `sms_message` (`SmsDispatchService.php:79`), page `SmsMessages` in `src/manifest.json` (columns created, recipientMsisdn, provider, templateId, status, attempts) | kept |
| Mail record | `lib/Outbound/MessageRecorder.php:134` `start()`, :256 `handedOver()`, :306 `deliveryReported()` | channel `notifynl` declared at `ChannelReportingCapabilities.php:48` |
| Broker | `lib/Service/BrokeredCallService.php:442` `prepare()`, :494 `dispatch()` | the Mollie binding's precedent |

## D1. Three bindings behind the existing seam

Each vendor is a class in `lib/Service/Sms/` implementing
`SmsProviderInterface`, wired into `SmsDispatchService` through a registry
rather than one more `if` in `resolveProvider()`:

- `CmComSmsProvider`, id `cmcom`: `POST {baseUrl}/message` on the CM.com
  Business Messaging API, the product token injected by the broker as the
  `X-CM-PRODUCTTOKEN` header.
- `MessageBirdSmsProvider`, id `messagebird`: `POST {baseUrl}/messages`,
  the access key injected as `Authorization: AccessKey`. `fetchStatus()`
  reads `GET {baseUrl}/messages/{id}`.
- `TwilioSmsProvider`, id `twilio`: `POST
  {baseUrl}/2010-04-01/Accounts/{accountSid}/Messages.json` with HTTP Basic,
  the account SID as plain configuration. `fetchStatus()` reads the same
  path with `/{sid}.json`.

The broker's injection is a static substitution of one `{secret}`
placeholder, and it cannot compute a value, as the credential note in
`lib/Service/Sms/RestNotifyNlProvider.php:16-34` records. CM.com and
MessageBird take a static header, so they fit. Twilio's Basic value is the
base64 of `SID:token`, so the broker holds that encoded value as the secret
and the header template is `Basic {secret}`. The form says so where the key
is picked.

All three dispatch through `BrokeredCallService::prepare()` and
`dispatch()` exactly as `MolliePaymentProvider::dispatch()` does, and fail
closed without a `credentialRef` (ADR-064).

Rejected: sending through the seeded `type: api` sources. Those carry the
token in `configuration.headers` (the CM.com comment says to paste it there),
which is the plaintext shape ADR-064 retires, and they belong to
OpenRegister's leaf. An SMS source for integriq is `type: sms` with a
`provider` value and a broker reference, the same shape the NotifyNL source
already has.

`SmsDispatchService` receives an `SmsProviderRegistry` holding every
binding, keyed by `getProviderId()`. `resolveProvider()` asks the registry.

## D2. An unknown provider is refused

`resolveProvider()` (:349) returns the log binding for any value except
`notifynl`. A source set to `twilio` today reports every message as sent and
sends none. With the registry, an absent value still means `log`, and a value
the registry does not hold throws `SmsProviderException` naming it. The
message is stored as `failed` with that reason, so the refusal is on the SMS
page.

## D3. Picking the source

`sendMessage()` takes an optional `sourceSlug`. When given, the dispatcher
loads that source and refuses one that is not `type: sms` or not enabled.
When absent, it keeps today's behaviour, the first enabled `sms` source, and
the chosen slug is written on the `sms_message` so the page says which
source sent.

The source form gets a provider picker for `type: sms`, fed by a new
`GET /api/sms/providers` listing the registry's ids, names and config
schemas. It follows the digital post picker in
`src/modals/v2/SourceFormFields.vue` (:425 to :640).

## D4. Delivery status for the three vendors

Polling: `fetchStatus()` for MessageBird and Twilio reads the vendor's status
endpoint, and `pollStatus()` (:252) already calls it. CM.com reports status
through its callback, so its `fetchStatus()` returns the stored status
unchanged and says so in `detail`, rather than guessing.

Callbacks: a new public route, `POST /api/sms/{provider}/status/{token}`,
on a new `SmsStatusController`, `#[PublicPage]` and rate limited like
`notifyNl#inbound`. It checks before it reads the body:

- The `{token}` is a per-source random value integriq generates when the
  source is saved, compared in constant time. Every vendor gets it, so a
  vendor that signs nothing is still not an open door.
- Where the vendor signs its callback, the signature is verified too:
  Twilio's `X-Twilio-Signature` and MessageBird's `MessageBird-Signature-JWT`.
  Verifying needs the signing key in process, which the broker never hands
  out, so the source stores it as `webhookSigningKey`, encrypted at rest
  through `OCP\Security\ICrypto`, as `RestNotifyNlProvider` stores its key.

A verified callback calls `handleStatusCallback()` (already on
`SmsDispatchService`), which ignores an unknown id and writes the status on
the known one.

## D5. NotifyNL e-mail

`RestNotifyNlProvider` gains `sendEmail(array $sourceConfiguration, string
$emailAddress, string $templateId, array $personalisation): DeliveryResult`,
posting to `/v2/notifications/email` through the same `dispatch()` (:354) and
JWT (:408). Its status read is the existing `fetchStatus()`, because
NotifyNL's `/v2/notifications/{id}` answers for mail too.

A new route, `POST /api/notifynl/emails`, on `NotifyNlController::sendEmail()`
behind a new `notifynl.email` action, validates the address, resolves the
template through the source's `templateMapping`, and records the send with
`MessageRecorder::start()` on channel `notifynl`, then `handedOver()` with
NotifyNL's id as the reference. The existing `notifyNl#inbound` callback
looks up the id in `sms_message` first and in the outbound message log
second, and calls `deliveryReported()` or `recipientFailed()` there.

Rejected: an `email_message` schema next to `sms_message`. The outbound
message log already exists to record a message to a person per recipient,
and `notifynl` is already a declared channel in it.

Rejected: a mail send through Nextcloud's mailer. NotifyNL is the row, and
NotifyNL sends from the government's own domain.

## Declarative versus imperative

Sending and receiving status callbacks are acts on external systems, so
they stay in services. No schema gains lifecycle behaviour.

## Seed data

`sms_message` (version 1.0.0) gains one property, `sourceSlug`, a string,
and moves to 1.1.0. The three seeded vendor fragments stay as they are for
OpenRegister's leaf. The mock register gains three `sms_message` demo rows,
one per new provider, in the states `sent`, `delivered` and `failed`, so the
SMS page shows every provider on a demo install.

## Risks

- Twilio's secret is the encoded Basic value, not the bare auth token. An
  administrator who stores the bare token gets a 401 from Twilio, and the
  binding reports it as a refused credential.
- Two places can hold a CM.com token: the seeded `api` source for
  OpenRegister's leaf and an integriq `sms` source. The SMS source form says
  which one integriq sends with.
- Throwing on an unknown provider changes a silent success into a visible
  failure on any source misconfigured today. That is the intent, and the
  release note names it.
