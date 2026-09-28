# Disposable Phase 6 owner/device review

The user selected creation of a local sample organization for owner/device review. This creates invented review records only; it does not record owner acceptance.

## Access

- Local login: `http://localhost:8000/login`.
- Organization: **DISPOSABLE Phase 6 Review etqgqnnfpo** (ID 26).
- Owner: `owner-etqgqnnfpo@review.example.test`.
- Technician: `technician-etqgqnnfpo@review.example.test`.
- Generated passwords are in the ignored private local file `storage/app/private/owner-review-access-etqgqnnfpo.json`, with file permissions 0600. No passwords are copied into this guide or source files. No invitation or external message was sent.
- Use separate browser profiles/private windows for the two roles. Each account already selects the review organization.
- For a physical phone on the same reachable network, use `http://<computer-LAN-IP>:8000/login`. The existing Docker binding is on port 8000 for all interfaces; no network-binding or firewall changes were made. `localhost` on the phone refers to the phone itself.

## Sample scenarios

| Job                                              | ID  | Starting state and review action                                                                                                                                                  |
| ------------------------------------------------ | --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DEMO 1 — Direct completion, checklist and parts  | 1   | Assigned to technician; confirmation unchecked. Required task is unfinished. Inspect notes, recorded costs and issued/returned parts, complete the task and finish directly.      |
| DEMO 2 — Awaiting manager confirmation           | 2   | Technician has submitted completed work. Owner confirms or reopens with a reason. Technician cannot modify submitted work.                                                        |
| DEMO 3 — Reopened job with preserved SLA history | 3   | Previously completed, then reopened. View the closed first cycle and active second cycle.                                                                                         |
| DEMO 4 — Manager-approved SLA hold               | 4   | On hold with recorded access reason. Owner resumes it before technician completion; both service clocks exclude the hold.                                                         |
| Generated DEMO preventive job                    | 5   | Assigned, manager confirmation required, required inspection task unfinished. Plan's automation is initially off and next due date is thirty days after the generated occurrence. |
| DEMO 6 — Owner-only assignment visibility        | 6   | Assigned to owner. Owner sees it; technician must not see it in lists, reports, CSV or direct job-card access.                                                                    |

The invented SLA calendar is Asia/Karachi, 09:00–17:00 every day, no holidays, response 30 working minutes and resolution 120 working minutes. It is sample configuration for these jobs, not a company-wide policy. No targets are backdated. The generated preventive job has no SLA configured initially.

## Stock and costs

One invented repair kit is held in two stores:

- DEMO Main store (ID 1): received 5, issued 2 to job 1, returned 1 unused; available **4.000 pcs**.
- DEMO Second store (ID 2): received 2; available **2.000 pcs**.
- Original issue ID: 3. Four referenced movements remain in the review organization.
- Job 1 recorded costs: labor **13.13 AED**, material **5.00 AED**, total **18.13 AED**. Manual actual cost remains unrecorded. Stock movements do not automatically add financial costs.

Owner can try an issue greater than the available balance and confirm it is rejected, record a referenced receipt, or exercise original-issue unused returns and reasoned corrections. Technician can inspect parts on assigned jobs but cannot manage global stock.

## Suggested review order

1. Sign in as technician. Open Maintenance and confirm five assigned jobs are visible. Follow jobs 1–5 above, noting anything unclear. Job 6 must be unavailable.
2. Sign in as owner. Confirm six jobs, review the submitted job, resume the held job, and inspect both store balances/history.
3. Open Operations overview → Reports and dashboards. Apply filters, inspect costs and preserved SLA cycles, then download CSV. Compare owner and technician visibility, including job 6.
4. Repeat job completion, confirmation/reopening, stock and export interactions on the physical phone and with keyboard navigation on desktop.
5. Record reviewer, date, device/browser, observed result and any findings in [the acceptance packet](PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md). Send findings for correction before recording an owner decision.

## Verification and retention

Authenticated HTTP/data verification passed: both logins succeed; owner sees six jobs and global stock, technician sees five jobs, receives 404 for the owner-only job and 403 for global stock; CSV visibility matches. Reopened job has two SLA cycles, costs total 18.13 AED, balances are 4.000/2.000, automation is off, and the private access file mode is 0600. Browser automation of the login page timed out; no browser or physical-device pass is claimed. The existing implementation's 277-test checkpoint remains separate from this retained review dataset.

These records deliberately remain available for the owner's review. They are not testing-database fixtures and are not scheduled for automatic deletion. Keep all review work in organization 26. After the review, cleanup should target only this recorded organization, its synthetic users 33/34 and its private access file, with organization relationships checked before removal. No existing organization was edited by the setup.
