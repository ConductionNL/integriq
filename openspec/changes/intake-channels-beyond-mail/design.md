# Design: intake-channels-beyond-mail

Kind: code. Size M. One adapter contract, one routing rule, one normalised
inbound shape. Three of the cluster's six candidates are recorded and not
built, which is why a cluster with a `must` in it is M.

## D1. One inbound shape, or every consumer learns every channel

FreeScout sells channels as modules: "sms-tickets, whatsapp $4, telegram $4,
facebook $4, twitter $14". That pricing tells you the shape of the work.
Each is a receiver, and none of them is a new idea about what a case is.

So the contract normalises: channel id, correspondent, text, attachments,
optional location, raw payload. A consumer reads the normalised shape and
never the raw one. Keeping the raw payload beside it is what lets a channel
be debugged without a second integration.

`describe()` exists for the same reason it exists in
`registry-backed-field-source`: without it, a caller has to know each channel
by name, which is the coupling the contract is meant to remove.

## D2. Routing is a rule, and no rule means an inbox

An unroutable message has three possible fates. Dropped, which loses a
resident's report. Opened as a default case, which fills the case list with
noise nobody owns. Or held for review with its reason.

The third is the only one a gemeente can defend, and it is the same argument
the mail lane makes about auto-replies: "a gemeente mailbox gets more
auto-replies than messages and each one opens a case today". Holding is
cheap; a wrong case is not.

## D3. The submission endpoint already exists and is generalised, not copied

`open-formulieren-intake` REQ-001 ships a signed inbound submission webhook,
public, gated by a signature, mirroring the Peppol and NotifyNL inbound
endpoints. C-intake-21 asks for the same thing with the mapping made
configuration.

Verifying the signature before reading the body is not a detail. A public
endpoint that parses first is a public endpoint that can be attacked with a
payload, and the sweep already found what a weak inbound trust model costs:
"a forged `In-Reply-To` landed one customer's mail on another's case".

Refusing a mapping at configuration time rather than at submission time is
the other half. A mapping that names a field the case type does not have
fails once, in front of the administrator who wrote it, instead of every time
a resident submits.

## D4. Location and media are first class because the highest-volume case has them

The lane's clause on C-intake-35 is the reason this candidate is in scope at
all: "meldingen openbare ruimte is the highest-volume case type a gemeente
has". A report without its coordinates and its photo is a report somebody has
to phone back about.

So the shape carries them, and a channel that has none writes none. Inventing
a coordinate from an IP address or a municipality centroid would be the
adjacent answer: plausible, precise-looking and wrong.

## D5. Replying on the channel that wrote to you

A resident who writes on WhatsApp and gets an e-mail has been answered on our
terms, not theirs. The channel id and the correspondent travel with the
message so the reply has somewhere to go.

`unsupported by this channel` is the third value again, the same discipline
the outbound message log uses for delivery states. A silent fallback to mail
looks like success to everyone except the resident.

## D6. Three candidates recorded, and why each is not this change

C-intake-4 is the Nextcloud smart picker, named by the lane as "one of the
ten Nextcloud platform integration points dossiq does not register". Building
it here would put one of those ten in an integration app instead of in the
programme decision D9 asks about.

C-intake-12 and C-tasks-and-phases-31 are a native mobile application. Both
rest on documented passers only, which D21 caps as an upper bound, and the
lane records that none of the four driven Dutch systems ships one either.
Whatever the answer is, it is a product decision and not a connector.

Recording each with its reason is the cheap half. The expensive half is
somebody sizing a mobile app into an integration cluster.
