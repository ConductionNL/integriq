---
kind: code
---

# Proposal: sandboxed-tenant-scripts

## Summary
Ruben decided on 30 Sep 2026 (build-all DECISIONS row 36) that integriq
will run tenant scripts later, in a sandbox, and not in
`gateway-endpoint-transform-and-plugins`. That change (archived as
`2026-09-29-gateway-endpoint-transform-and-plugins`, design D3) refuses the
`javascript` rule: the editor no longer offers it, the register's rule
`type` enum leaves it out, and `EndpointService` throws when an old one
runs (REQ-GTP-003). This change records the future work: a script rule
that runs in an isolated runtime, so an administrator can add a few lines
of logic to the request pipeline without writing a plug-in app.

Until this change is built, REQ-GTP-003 stands and the `javascript` rule
stays refused.

## What changes (when built)
- A rule type for a tenant script, run outside the PHP process in an
  isolated runtime with no network, no file system, no access to
  Nextcloud or OpenRegister, a CPU and memory ceiling and a wall-clock
  timeout. The script sees the request (or the answer) as data and
  returns data; nothing else crosses the boundary.
- The runtime is a sidecar the administrator enables (an ExApp or a
  container next to Nextcloud); without it the rule type is refused, as
  today.
- Every run leaves a call-log entry with the duration and the outcome; a
  script that exceeds a ceiling fails the request with a clear message.
- Old `javascript` rules are not revived: an administrator recreates the
  logic as a script rule on purpose.

## Out of scope
Running scripts inside the PHP process in any form (V8Js, an embedded
interpreter, `eval`). Plug-ins from sibling apps stay the supported way to
add trusted logic (`EndpointRulePluginInterface`, REQ-GTP-002).

## Rows
- integriq `gw-plugins` is built by the plug-in contract; its "or a
  script" half is this change. No row moves until it is built.
