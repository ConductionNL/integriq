# Design: sandboxed-tenant-scripts

Status: recorded, not designed. Written on 1 Oct 2026 to keep Ruben's
decision (DECISIONS row 36: "later, sandboxed") from being lost. The
design is the first task.

## Questions the design must answer
1. The runtime: a WebAssembly host (for example a JavaScript engine
   compiled to WASM), a locked-down Deno or Node sidecar, or an ExApp.
   Criteria: isolation without trusting the script, a start-up cost that
   fits a request (tens of milliseconds), and an image the fleet can ship.
2. The contract across the boundary: request and answer as JSON in, JSON
   out; which headers and parameters a script may read and change.
3. Limits and their defaults: CPU time, memory, wall clock, output size.
4. Who may write a script: admin only (the rule schema is admin-written
   today), and whether a script needs a second person's approval before it
   runs on a public endpoint.
5. How the refusal in REQ-GTP-003 changes: the new rule type gets its own
   name, so `javascript` stays refused and an old rule never runs by
   accident.
