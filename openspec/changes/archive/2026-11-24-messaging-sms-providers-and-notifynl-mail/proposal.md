---
kind: code
depends_on: []
---

# Proposal: messaging-sms-providers-and-notifynl-mail

## Summary

Integriq sends a text message only through NotifyNL. CM.com, MessageBird and
Twilio exist as seeded source records in mock mode that another app's
dispatcher uses, and NotifyNL is wired for text but not for mail. This change
adds three SMS bindings behind the provider seam integriq already has, lets a
caller pick which SMS source sends, refuses a provider value integriq has no
binding for, and adds NotifyNL e-mail through the same NotifyNL binding.

## Why

This change covers two rows.

- `integriq:msg-sms`, "Send text messages through a provider such as CM.com,
  MessageBird or Twilio", rated partial and built. Two competitors rate it
  yes:
  - n8n: "packages/nodes-base/nodes/Twilio/Twilio.node.ts:47 'sms' and
    packages/nodes-base/nodes/MessageBird/MessageBird.node.ts:43 'sms' with
    :65 'send' send text messages".
  - mulesoft: "https://docs.mulesoft.com/composer/ms_composer_twilio_reference.md
    the Composer Twilio connector lets you 'send and receive text messages'
    with a Send Message action;
    https://anypoint.mulesoft.com/exchange/api/v2/assets?search=bird lists
    the Bird Connector".
  The matrix note: "Integriq itself sends SMS only through NotifyNL, via a
  route no integriq page calls. CM.com, MessageBird and Twilio are just seeded
  source configs in mock mode that another app's dispatcher uses."
- `integriq:nl-notifynl`, "Send text and mail notifications through
  NotifyNL", rated partial and built. No competitor rates yes. The note:
  "The row asks for text and mail notifications through NotifyNL; only the
  SMS channel is implemented." It rides with `integriq:msg-sms`, because its
  missing half is the same NotifyNL binding and send service.

No demand row for either.

## What integriq already has

- The seam. `lib/Service/Sms/SmsProviderInterface.php:39` declares
  `getProviderId()`, `getProviderName()`, `getConfigSchema()`, `send()` and
  `fetchStatus()`. Its docblock says a new vendor "is added by implementing
  this interface, never by editing SmsDispatchService".
- The dispatcher. `lib/Service/SmsDispatchService.php:136` `sendMessage()`
  normalises the number to E.164, writes an `sms_message` and sends.
  `resolveProvider()` (:349) knows `notifynl` and returns the log binding for
  every other value. `resolveActiveSource()` takes the first enabled source of
  type `sms`, so a caller cannot choose between two.
- The route. `appinfo/routes.php:165-167`: send, status and a signed status
  callback on `NotifyNlController`.
- The NotifyNL binding. `lib/Service/Sms/RestNotifyNlProvider.php` signs a
  JWT per request and posts to `/v2/notifications/sms` (:231); its status
  read is `/v2/notifications/{id}` (:264), which NotifyNL answers for any
  notification type.
- The seeded vendor sources. `lib/Settings/register.d/cmcom-sms-source.json`,
  `messagebird-sms-source.json` and `twilio-sms-source.json` are `type: api`
  sources in mock mode, whose comments say they feed OpenRegister's
  `MessageDispatchProvider` and pipelinq. OpenRegister's leaf
  (`lib/Service/Integration/Providers/MessageDispatchProvider.php` on
  ConductionNL/openregister `development`) is "the raw transport only" and
  keeps no delivery record.
- The outbound message log. `lib/Outbound/MessageRecorder.php` records a send
  per recipient, and `lib/Outbound/ChannelReportingCapabilities.php:48`
  already declares `notifynl` as a channel that reports delivery.

## What this change builds

1. `CmComSmsProvider`, `MessageBirdSmsProvider` and `TwilioSmsProvider`
   implementing `SmsProviderInterface`, dispatching through the credential
   broker like `MolliePaymentProvider` does.
2. Delivery status for the three: polling where the vendor has a status read,
   and a signed or tokened callback route for all three.
3. Source choice on a send: an optional `sourceSlug`, and a provider picker on
   the SMS source form built from the registered bindings.
4. A refusal of an unknown provider value, instead of the silent log
   fallback.
5. NotifyNL e-mail: a send through `/v2/notifications/email`, recorded in the
   outbound message log, with its status read and its callback handled.

## Out of scope

- WhatsApp. It has its own sources and its own row.
- Changing OpenRegister's `MessageDispatchProvider` or pipelinq's SMS
  clients. They keep using the seeded `type: api` sources.
- Plain SMTP mail. That is Nextcloud's mailer and the outbound sender
  identity change, not NotifyNL.
- A page that sends a text by hand. The row is about the provider, and the
  send is a sibling app's act.
