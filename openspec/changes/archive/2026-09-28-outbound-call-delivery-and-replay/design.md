# Design: outbound-call-delivery-and-replay

Kind: code. Size M. A call becomes a record, a retry becomes a policy, and a
replay names what it ran under. Every act reuses machinery integriq already
ships, which is why a cluster with four `must` candidates is M and not L.

## D1. The call is the record, and the trace is the thread

`execution-trace` already mints a trace id at four entry points and keeps an
ordered step timeline. What it does not do is make a single outbound call a
thing an administrator can list, filter and act on. The call record carries
the trace id, so the two views answer different questions about the same run:
the trace says what happened in this execution, the call log says what
happened to this partner today.

Redaction is not re-invented. `execution-trace` REQ-003 redacts before
buffering, and the call recorder uses the same path. A log that stores a
bearer token is a breach with a search box.

## D2. Replay is `dead-letter-replay`, pointed at a new record

The replay act exists: listed, inspected, audited, bulk with per-item
outcomes, with a UI. Building a second one would give integriq two audit
trails for the same verb.

Two properties come along and both matter. `execution-trace` REQ-006 replays
through the original entry point's real dispatch path, so a replayed call is
not a different call that happens to look similar. `webhook-signing`
REQ-WHS-001 signs it, so a receiver cannot tell a replay from a first
delivery by its signature, which is the point: the receiver is recovering
from an outage, not auditing our retry logic.

## D3. A dry run before a replay, because the partner is not ours

`execution-trace` REQ-005 already guarantees a dry-run replay performs no
writes. Surfacing it on the call log is the cheap half, and it is the one an
administrator uses when the failure was a bad payload rather than a downtime.

## D4. Firing by hand is a separate verb from replaying

Replaying re-sends something that already happened. Firing by hand sends
something that has not. Five driven passers do both, and the loudest list
names them together: "Replaying a failed delivery, or firing one by hand".

They share a permission and a record shape, and they differ in one field: the
record says which it was, and names the principal for a hand-fired call. To
the receiver they are the same request, which is deliberate. A receiver that
behaves differently for a hand-fired delivery is a receiver we cannot test.

## D5. The schedule is a policy because the partner sets it

C-integrations-12's clause is the whole design: "the retry schedule on a
Digikoppeling call is a policy, not a constant". One partner asks for six
attempts over a day, another refuses more than three. dossiq's
`StufRetryJob.php` currently holds a hardcoded schedule for everybody, and
the fix is not a better constant.

Recording the policy on the call matters as much as configuring it. Without
that, a call that stopped after two attempts cannot be explained a month
later, when the policy has changed twice.

## D6. Mapping versions, or a replay is a different call

C-integrations-45 reads `yes` for dossiq because integriq already ships the
mapping editor. The gap opens at replay: a mapping edited between the failure
and the retry means the replayed call carries a different payload, and
nothing says so.

So a mapping edit creates a version, a call names the version it ran under,
and a replay asks which to use. Offering both is better than defaulting to
either. The original version reproduces what the partner should have got; the
current one is what fixes a payload the partner rejected. The administrator
knows which situation they are in, and the record says which they chose.

## D7. Verdicts and pre-checks: integriq asks, the owner decides

C-integrations-16 and C-integrations-20 are both an outside system having an
opinion about our record. The temptation is to let integriq act on it, and
that would put a case decision in the integration layer.

So a verdict is stored beside the object and changes nothing. A pre-check
returns `allow`, `refuse` or `no answer` and the caller decides. The third
value is the one that keeps this honest: a timeout is not permission, and a
pre-check that defaults to `allow` on silence is a guard that is not a guard.

## D8. Two candidates recorded rather than built

C-integrations-6, a scheduled mirror, is the synchronisation engine with a
schedule. Writing a second capability for it would duplicate REQ-001 through
REQ-010 of a spec that is already done.

C-integrations-32, federation, is a Nextcloud platform capability and belongs
to the ten platform integration points that decision D9 asks about as a
programme. dossiq already reads `partial` on
`CaseSharingService::createFederatedShare`.

Both are recorded so nobody rediscovers them, which is the reasoning D17
applied to the whole `not` bucket.
