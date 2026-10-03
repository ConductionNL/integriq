# Tasks: sandboxed-tenant-scripts

### Task 1: Design the isolated runtime
- [ ] Answer the five questions in design.md, with a measured start-up
      cost for the chosen runtime, and write the spec delta (a new
      requirement in rule-pipeline beside REQ-GTP-003).

### Task 2: The script rule and its runtime
- [ ] Implement the rule type, the sidecar client and the limits.
- [ ] Test: a script that loops, allocates or reaches for the network
      fails within its ceiling; a well-behaved script reshapes the answer.

### Task 3: Editor, docs and release note
- [ ] Offer the rule type in the rule editor only when the runtime is
      enabled; document the contract; release note.
