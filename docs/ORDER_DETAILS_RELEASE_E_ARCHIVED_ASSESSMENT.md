# FlowTracker Order Details - Release E verification gate

Before: `AB_B0` / After: `AB_B1`. Required samples: 5 per order/section/run.
Distinct representative orders: 3 (required 3).
Allowances: SQL p50 no regression; DB p50 +20%; request p95 +20%.

**Performance gate: INCOMPLETE**  
**Manual/functional gate: INCOMPLETE**  
**Overall release gate: INCOMPLETE**

| Order | Request | B0 n | B1 n | SQL p50 B0 | SQL p50 B1 | SQL delta | DB p50 B0 ms | DB p50 B1 ms | p95 B0 ms | p95 B1 ms | Result |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 308 | initial | 0 | 2 | n/a | 26.00 | n/a | n/a | 20.54 | n/a | 90.75 | INCOMPLETE |
| 308 | products | 0 | 1 | n/a | 7.00 | n/a | n/a | 39.88 | n/a | 50.45 | INCOMPLETE |
| 308 | workflow | 0 | 1 | n/a | 70.00 | n/a | n/a | 67.64 | n/a | 144.36 | INCOMPLETE |
| 308 | attachments | 0 | 1 | n/a | 14.00 | n/a | n/a | 9.69 | n/a | 19.21 | INCOMPLETE |
| 308 | activity | 0 | 1 | n/a | 16.00 | n/a | n/a | 28.94 | n/a | 46.00 | INCOMPLETE |
| 310 | initial | 0 | 3 | n/a | 27.00 | n/a | n/a | 25.18 | n/a | 512.53 | INCOMPLETE |
| 310 | products | 0 | 2 | n/a | 7.00 | n/a | n/a | 75.78 | n/a | 204.37 | INCOMPLETE |
| 310 | workflow | 0 | 2 | n/a | 84.50 | n/a | n/a | 149.94 | n/a | 586.07 | INCOMPLETE |
| 310 | attachments | 0 | 2 | n/a | 14.00 | n/a | n/a | 14.12 | n/a | 33.10 | INCOMPLETE |
| 310 | activity | 0 | 2 | n/a | 16.00 | n/a | n/a | 16.95 | n/a | 37.34 | INCOMPLETE |
| 333 | initial | 18 | 9 | 30.00 | 30.00 | +0.00 | 24.54 | 27.13 | 223.36 | 264.36 | INCOMPLETE |
| 333 | products | 16 | 9 | 11.00 | 11.00 | +0.00 | 8.55 | 10.03 | 53.78 | 54.59 | INCOMPLETE |
| 333 | workflow | 16 | 8 | 98.00 | 75.00 | -23.00 | 103.69 | 85.73 | 306.04 | 358.30 | INCOMPLETE |
| 333 | attachments | 7 | 8 | 17.00 | 17.00 | +0.00 | 15.32 | 16.77 | 39.55 | 37.10 | INCOMPLETE |
| 333 | activity | 5 | 8 | 19.00 | 19.00 | +0.00 | 20.44 | 17.93 | 47.11 | 41.19 | PASS |

## Missing or mismatched performance evidence
- Order 308 / initial: only 0 before / 2 after samples (min 5).
- Order 308 / products: only 0 before / 1 after samples (min 5).
- Order 308 / workflow: only 0 before / 1 after samples (min 5).
- Order 308 / attachments: only 0 before / 1 after samples (min 5).
- Order 308 / activity: only 0 before / 1 after samples (min 5).
- Order 310 / initial: only 0 before / 3 after samples (min 5).
- Order 310 / products: only 0 before / 2 after samples (min 5).
- Order 310 / workflow: only 0 before / 2 after samples (min 5).
- Order 310 / attachments: only 0 before / 2 after samples (min 5).
- Order 310 / activity: only 0 before / 2 after samples (min 5).
- Order 333 / initial: baseline users differ or are unknown.
- Order 333 / products: baseline users differ or are unknown.
- Order 333 / workflow: baseline users differ or are unknown.
- Order 333 / attachments: baseline users differ or are unknown.

## Manual checks requiring attention
- No manual/browser acceptance attestation provided.

## Limits of this gate
- Server baseline logs cannot verify browser repaint, authorization changes, supplier delivery, or realtime request suppression. Manual checks are separately required.
- Run IDs and matching users are checked, but workflow state, cache temperature, hardware, and data snapshots must be held equivalent by the tester.
- p95 with small samples is noisy. A reported regression needs investigation and repeat measurements; do not automatically waive it.
- This tool is read-only. A PASS is a recorded release gate, not proof that production has been deployed or monitored.
