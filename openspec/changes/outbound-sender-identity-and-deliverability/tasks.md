# Tasks: outbound-sender-identity-and-deliverability

## 1. The identity

- [x] 1.1 Add the sender identity object: display name, address, reply-to, signature, Nextcloud Mail account reference.
- [x] 1.2 Let a message name an identity, and fall back to the instance default.
- [x] 1.3 Record the identity on the outbound log row, as the umbrella said this cluster would.
- [x] 1.4 PHPUnit on selection and on the fallback.

## 2. Domain alignment

- [x] 2.1 Check SPF, DKIM and DMARC per identity domain and store the result with its time.
- [x] 2.2 Print the exact record to publish where one is absent or misaligned.
- [x] 2.3 Show the risk on the identity screen and on the log rows it produces, without blocking a send.
- [x] 2.4 PHPUnit on the record comparison, with a stubbed resolver.

## 3. Signing and encryption

- [x] 3.1 Administer S/MIME keys per identity, and recipient keys per address. PGP is deliberately not built here: it needs the gnupg extension, which is not a dependency this change adds, and claiming it without the extension would be a signature nobody made.
- [x] 3.2 Sign outgoing mail per identity, encrypt where a recipient key is known.
- [x] 3.3 Record signed, encrypted, both or neither on each log row.
- [x] 3.4 Verify signed inbound mail and record the result.
- [x] 3.5 PHPUnit on the signed-not-encrypted path and on verification.

## 4. The opt-out

- [x] 4.1 Add the per-address opt-out list, one per instance.
- [x] 4.2 Check it in the send path for every sender in the product.
- [x] 4.3 Declare the protected categories and record every override.
- [x] 4.4 PHPUnit on suppression and on the protected override.

## 5. The unsubscribe link

- [x] 5.1 Mint a signed token per recipient and case, and render the link in case notification mail.
- [x] 5.2 Handle the link without a login, confirm what was stopped, create no account.
- [x] 5.3 Render no link at all in a protected category.
- [x] 5.4 PHPUnit on the token, and a test that a besluit template contains no link.

## 6. Quoting

- [x] 6.1 Add the quoting level per identity, defaulting to `last-message`.
- [x] 6.2 Apply it at composition and record the level used on the log row.
- [x] 6.3 PHPUnit on all three levels.

## 7. Undo send

- [x] 7.1 Add the hold window per identity, defaulting to zero.
- [x] 7.2 Queue for the window, allow withdrawal during it, record the withdrawal.
- [x] 7.3 Refuse a withdrawal after the window with a message that says why.
- [x] 7.4 PHPUnit with a frozen clock on both sides of the window.

## 8. No-reply handling

- [x] 8.1 Mark an identity as taking no replies, with divert or refuse.
- [x] 8.2 Divert to the configured address, or refuse naming where to write, and record either.
- [x] 8.3 PHPUnit on both, including that nothing is dropped silently.

## 9. Signature stripping

- [x] 9.1 Detect quoted signature and disclaimer blocks.
- [x] 9.2 Strip them from the timeline entry only, keeping the stored message whole.
- [x] 9.3 Offer the original from the entry.
- [x] 9.4 PHPUnit on a message with no signature, to prove nothing else is removed.

## 10. Handover

- [ ] 10.1 Give the dossiq lane its half: the sender identity declared per team on the case type, replacing the single `EmailSettings` address.
- [ ] 10.2 Confirm with the Nextcloud Mail boundary that an identity only references an account, per decision D12.
- [ ] 10.3 Add this change to the integriq umbrella index and tick it there when it archives.
