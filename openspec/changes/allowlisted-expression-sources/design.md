# Design: allowlisted-expression-sources

Kind: code. Size S. One registry keyed by prefix, one allowlist with no
wildcard, and a redaction rule that already exists. No parser, no evaluator,
no operator.

## D1. The prefix is the seam between two apps

Valtimo's value resolvers are the shape the second read calls "the single
most reusable idea" it found: one expression language, eleven prefixes, and
each prefix a source. Splitting it at the prefix is what lets the language
sit in openregister and the reach sit in integriq.

The rule is the depth study's own: "A registry lookup belongs to integriq."
`doc:` and `case:` read the object under the expression and are openregister's
business. `env:` reads the machine. The next one will read a registry, and
that is integriq's by the same sentence.

## D2. No fall-through, ever

A prefix with no registered source fails. It does not try the next source, it
does not return empty, and it does not resolve as a literal. Fall-through is
how `env:DATABASE_PASSWORD` becomes the string `env:DATABASE_PASSWORD` in an
outbound payload, or worse, becomes empty and makes a condition true.

## D3. The allowlist has no wildcard, because a wildcard is not an allowlist

`*` is the entry every administrator adds at three in the morning during an
incident and nobody removes. Refusing it at save is cheap and it is the whole
control. The lane's clause says what it is protecting against in one line:
"an expression language that can read the environment is a data exfiltration
path, and the allowlist is the answer."

An empty list meaning all is the same failure wearing different clothes, so
an empty entry is refused too, and an empty list resolves nothing.

## D4. A refusal names the key and returns nothing

Two wrong answers are available and both look like success. Returning the
value defeats the allowlist. Returning an empty string makes a condition
quietly true and a template quietly blank, and neither shows up in a test.

So a refusal is an error naming the key. An expression that reads a key
nobody allowed should stop, loudly, in front of the person who wrote the
expression.

## D5. Redaction is reused, not rebuilt

`execution-trace` REQ-003 redacts a snapshot before any step is buffered.
That is already the right moment: redacting on read leaves the value on disk,
and integriq's traces are readable by more principals than the allowlist's
editors.

Letting a source declare a resolved value a secret is the addition. A key can
be allowlisted for use and still be unfit to appear in a trace, and those are
two different decisions.

## D6. Writing is declared and not built

Valtimo's interface runs both ways, `resolve` and `store`, and the study
records that. A write-through-expression path into the environment is not a
capability we want, and a write into a registry is a different proposal with
a different risk.

So `describe()` says whether a source supports writing, `env:` says it does
not, and a refused write is an error rather than a no-op. Declaring the
capability now means the second source does not have to invent how to say
"no".

## D7. Building it before the second prefix exists

D-casetype-20 is rated low on its own. Its value is positional: it is the
smallest source anyone will ever add, so it is the cheapest one to specify a
contract against. After three prefixes exist, the contract is a refactor of
three call sites instead of a design.
