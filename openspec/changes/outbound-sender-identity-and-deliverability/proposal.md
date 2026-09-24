---
kind: code
depends_on: [outbound-communication-log]
---

# Proposal: outbound-sender-identity-and-deliverability

## Summary

Mail leaves the building under one address, from whatever domain the
deployment happened to configure, quoting the whole dossier back, with no
way to stop it, take it back, sign it or honour a person who asked not to
be written to. This change gives outbound mail an identity the organisation
chooses and the receiver trusts.

## Candidates and cluster

Cluster 61 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Outbound sender identity
and deliverability". Owner integriq, size M, decision D12. Nine candidates,
no `must`, eleven passers of which eight driven. Proving system
request-tracker. The cluster's mechanism line: "extend integriq's outbound
mail path; dossiq declares the sender per team on the case type".

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-communication-44 | should | no | more than one outbound sender address, chosen per team or case |
| C-communication-50 | should, documented | partial | outbound mail aligned to the customer's own domain (SPF, DKIM, DMARC) |
| C-communication-51 | should | no | S/MIME and PGP on case mail, keys administered in the product |
| C-communication-24 | should | no | a platform-wide opt-out held per recipient |
| C-communication-66 | should | no | an unsubscribe link in case notification mail |
| C-communication-43 | should | no | how much case history is quoted into the outgoing mail |
| C-communication-26 | should | no | undo send within a short window |
| C-communication-45 | could | no | handling of mail to a no-reply address |
| C-communication-65 | could, documented | no | the quoted signature and disclaimer stripped from the timeline entry |

## Why it opens now

The integriq umbrella recorded this cluster as deliberately not opened,
under the name cluster 60, with the reason: "It now builds on a Nextcloud
Mail account rather than an integriq one, which is the re-read wave 1 asked
for." That re-read is done, and it is the first design decision below. The
umbrella also said `outbound-communication-log` "adds the sender identity
as one more field on its record when cluster 61 lands". It does, and this
change is where the field's meaning is specified.

## The evidence, verbatim

Quoted from `procest/_round4/discovery/candidates.md`, best-evidence
column.

- **C-communication-44**, "should, 'from' on a besluit is a policy
  decision, not a deployment variable". Best evidence: "znuny: Outbound
  mail profiles and sendmail config (AdminSendmailConfig.pm, the Outbound
  Email Profiles screen)", with dimpact-zac driven beside it. dossiq: "no,
  lib/Settings/EmailSettings.php:66 holds one email_from_address for the
  instance; nothing picks a sender per team or per case".
- **C-communication-50**, "should, a besluit or an ontvangstbevestiging
  that lands in spam because the case system spoofs the gemeente's domain
  is an Awb delivery failure, and the vendor documents this as a known
  conflict rather than as solved". Best evidence: "jira-service-management:
  Send notification emails from your own domain". Documented, never counted
  in a driven tally (D21).
- **C-communication-51**, "should, a gemeente sends medische and financiële
  stukken by mail today". Best evidence: "request-tracker: Ticket, Crypt
  (share/html/Ticket/Crypt.html), queue signing and encryption,
  Admin/Tools/GnuPG.html". Five driven passers: freescout, otobo,
  request-tracker, zammad, znuny.
- **C-communication-24**, "should, AVG and the Telecommunicatiewet both
  make this the sender's duty". Best evidence: "odoo: mail_blacklist.py,
  mail_thread_blacklist.py".
- **C-communication-66**, "should, and it needs a rule about which updates
  may be stopped: a besluit notice may not". Best evidence: "gitlab: The
  unsubscribe link and the %{UNSUBSCRIBE_URL} placeholder in the Service
  Desk templates".
- **C-communication-43**, "should, the whole dossier quoted back to a
  bezwaarmaker is a disclosure". Best evidence: "freescout: Settings,
  General email_conv_history, email_user_history".
- **C-communication-26**, "should, a wrong besluit letter recalled in
  thirty seconds is a complaint that never happens". Best evidence:
  "freescout: GET /conversation/undo-reply/{thread_id}/{token}".
- **C-communication-45**, "could". Best evidence: "freescout: Manage,
  Modules, noreply". dossiq: "no, zero hits for noreply or a bounce
  handler; lib/Service/CaseEmailService.php:109 only guards the reserved
  example.nl domain".
- **C-communication-65**, "could". Best evidence:
  "jira-service-management: Hide email signatures from the work item view
  and portals". Documented, never counted in a driven tally (D21).

## What integriq builds

- **A sender identity object.** A named outbound identity with a display
  name, an address, a reply-to, an optional signature and the Nextcloud
  Mail account it sends through. A message names an identity, and the
  identity decides what the receiver sees.
- **Domain alignment, reported.** For each identity, the SPF, DKIM and
  DMARC state of its domain is checked and shown, with the exact record the
  administrator has to publish where one is missing.
- **Signing and encryption.** S/MIME and PGP keys administered per
  identity, outbound signing per identity, encryption where a recipient key
  is known, and verification of signed inbound mail.
- **A recipient opt-out.** One list per instance, honoured by every sender
  in the product, with the categories that may never be stopped named
  explicitly.
- **An unsubscribe link.** Case notification mail carries a link that adds
  the recipient to the opt-out for that case, and a statutory notice
  carries no link because it cannot be stopped.
- **A quoted history level.** How much of the case history is quoted is a
  setting per identity, with an explicit "nothing" option.
- **Undo send.** A hold window per identity during which a queued message
  can be taken back before it leaves.
- **No-reply handling.** An identity marked as taking no replies diverts or
  refuses inbound mail with a message that says where to write instead.
- **Signature stripping.** The quoted signature and disclaimer are removed
  from the timeline entry, while the original message stays whole in the
  log.

## How dossiq consumes it

The cluster's mechanism names dossiq's half: it declares the sender per
team on the case type. There is no dossiq change for that yet, so dossiq
needs one. dossiq holds the declaration, integriq holds the identity, and
dossiq's `EmailSettings.php` single instance address stops being the only
answer. dossiq's `inbound-mail-filters` is the inbound sibling of this
change and is unaffected.

## Affected projects

- [x] `integriq`: this change, extending `outbound-communication-log`.
- [ ] `dossiq`: declares the sender per team or per case type. Needs a
      change of its own.
- [ ] Nextcloud Mail: owns the account and its OAuth 2.0 flow, per decision
      D12 as Ruben answered it. An identity references an account, it does
      not replace one.
- [ ] `openregister`: owns the audit trail every send is written to.
      Unchanged.

## Out of scope

- The mail account, its credentials and its OAuth flow. D12 puts those in
  Nextcloud Mail.
- Inbound filtering and routing. That is dossiq's `inbound-mail-filters`
  and integriq's `intake-channels-beyond-mail`.
