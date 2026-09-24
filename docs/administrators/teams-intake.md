# Open a case from a Microsoft Teams message

A message in Teams opens a case, or joins one that already exists. The person
writing stays in Teams. The answer comes back into the same conversation.

This is one more intake channel, not a second idea about what a case is. A
Teams message goes through the same routing rules, the same review inbox and
the same case-number detection as a mail or a form submission.

## What you create in Teams

You need an **outgoing webhook** on the team. Teams sends every message that
mentions it to a URL you choose, and signs the post.

1. Open the team, choose **Manage team**, then **Apps**, then **Create an
   outgoing webhook**.
2. Give it a name. People type that name to reach it, so keep it short.
3. Point the callback URL at your integriq endpoint, over HTTPS.
4. Copy the security token Teams shows you once. You cannot read it again.

The token is base64. Paste it exactly as Teams gives it, padding and all.

## What you configure in integriq

The channel is a source, listed and tested on the Sources screen like any
other. Give it `type: intake-channel`, `channelId: teams` and a configuration:

```json
{
  "mock": false,
  "casePattern": "/\\b([A-Z]{2,10}-\\d{4}-\\d{1,8})\\b/",
  "serviceUrl": "https://smba.trafficmanager.net/emea/",
  "credentialRef": "teams-connector"
}
```

`casePattern` is the case number shape your organisation uses. Leave it out and
integriq reads the fleet default, `ZAAK-2026-0042` and its like. A pattern that
does not compile is refused, not quietly replaced: detecting with the wrong
pattern links a message to the wrong case.

`mock: true` records every reply and sends nothing. Use it while you are
setting the channel up.

The endpoint that receives the post needs a `webhook_signature` rule:

```json
{
  "type": "webhook_signature",
  "scheme": "teams",
  "header": "Authorization",
  "secret": "<the token Teams showed you once>"
}
```

Teams signs with the base64-decoded bytes of that token, and integriq checks it
that way. A post that fails the check gets a 401 and no rule downstream runs.
There is no setting that accepts an unverified post, because this route opens
cases.

## The one thing that bites

**Teams waits five seconds for an answer.** Not for the case to exist, for the
HTTP response. Opening a case takes longer than that on any real day.

So the endpoint acknowledges the message straight away and the case opens
behind it. The acknowledgement says the message arrived. The case number
follows in the conversation once the owning app has minted it.

If you build a rule that opens the case inline, Teams times out, the person
sees an error, and they write the message again. You now have two cases.

## Replying

The answer is posted into the conversation the message came from, through the
Bot Framework connector. It never leaves by mail and never lands in another
thread.

A reply needs an access token for the connector, resolved through
`credentialRef` the way every other outbound credential is. Without one the
channel reports that it cannot answer. It does not fall back to another route:
a reply that arrives somewhere the person never wrote looks like success to
everyone except them.

## Attachments

A file someone uploads in Teams stays in Teams. The message carries the
pre-authenticated download URL, and that URL travels with it. Integriq does not
fetch the bytes while answering, because fetching them would spend the five
seconds.

## What is not covered

- **Teams calls and meetings.** A meeting is not an intake channel.
- **Posting case updates into a channel** beyond the reply to the message that
  opened the case. That is the notification engine's work.
- **An Outlook add-in or an Office task pane.** Outlook already reaches cases
  through the shared mailbox, and an Office document reaches them through
  Files.

## Walk it once

Setup instructions that nobody has walked are a guess. Walk this one against a
real tenant before you trust it, and write down what you find either way.

At the time of writing this page has been walked against the documented
Microsoft behaviour and the adapter's own tests, not against a live tenant. The
five-second budget and the base64 token are the two points where a live walk is
most likely to correct it.
