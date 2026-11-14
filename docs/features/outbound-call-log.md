<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Outbound call log: the investigation window

Integriq logs every call it makes to a source: when, the status, the method,
the URL and how long it took. It does not store what was sent or what came
back. A request or response body can carry personal data, so it is kept only
while an administrator is investigating a problem with that source.

This page is for the administrator who has to find out why a partner system
answers wrongly.

## Open a window

1. Open the source under *Sources*.
2. In *Investigation window*, choose *Open investigation window*.
3. Enter the number of hours (1 to 72 by default) and the reason, for example
   "melding 4711: verkeerde zaaktypen", and choose *Open window*.

The page now says *Bodies are stored until* with the end time, and the reason.
Every call to this source until then keeps its request and response bodies in
the call log.

Choose *Stop early* to close the window before its end. Calls after that store
no bodies again.

Only a Nextcloud administrator sees the buttons. The endpoints refuse anyone
else as well.

## Read a call

Open *Source logs* and click a row. A call made inside a window shows its
request and response. A call made outside one says *No body stored: no
investigation window was open*.

## Settings

| App setting | Default | Meaning |
|---|---|---|
| `call_log_body_capture_max_hours` | 72 | The longest window an administrator can open |
| `call_log_body_retention_days` | 7 | How long stored bodies stay after the window ends |

Code cannot switch body storage on by itself: only the callers on
`BodyCapturePolicy::LOG_BODY_ALLOWLIST`, which read public data, may store a
body outside a window.
