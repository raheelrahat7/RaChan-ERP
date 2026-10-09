# Agents and commission reporting — Phase 2F backend contract

The existing commission allocation approval flow remains unchanged. This phase adds append-only clawback tracking and a team view. Clawbacks affect the **reporting** figure `net_contribution`; they do not post an accounting reversal or change a paid commission. `net_contribution` is the approved allocation's `net_company` minus recorded clawbacks. Until an allocation is approved, a commission's `net_contribution` is `null`. A negative value is possible when a clawback exceeds the company share. Team membership comes from the broker's mapped `user_id` and `crm_team_memberships`; unmapped brokers appear as `Unassigned`.

| Method | URI | Route name | Request |
| --- | --- | --- | --- |
| GET | `/real-estate/brokerage/data` | `real-estate.brokerage.data` | Optional `team_id`, `page` |
| GET | `/real-estate/brokerage/commissions/{commission}` | `real-estate.brokerage.commissions.show` | No body |
| POST | `/real-estate/brokerage/commissions/{commission}/clawbacks` | `real-estate.brokerage.clawbacks.store` | Required `expected_version` (commission version), `amount` (positive AED decimal string, two fractional digits maximum), `reason` (up to 2,000 chars) |

`viewTransactions` may read; `manageTransactions` may record clawbacks. Cross-organization IDs return 404 and forbidden writes return 403. List returns `{commissions: {data: [commission], current_page, last_page, total, ...Laravel pagination}, teamSummary: [team], filters, permissions: {read, record_clawback}}`. Detail returns `{commission, clawbacks}`. Each commission and clawback has `version` and `permissions`; clawbacks are immutable, so they need no update endpoint. A team summary row has `team_id`, `team_name`, `total_commissions`, `gross_commission`, `clawback_total`, and `net_contribution`.

```json
{
  "commission": {
    "id": 5, "broker_id": 9, "broker_name": "Nadia", "team_id": 3, "team_name": "Waterfront",
    "commission_amount": "6000.00", "currency": "AED", "allocation_status": "approved",
    "net_company": "2500.00", "agent_payable": "3000.00", "clawback_total": "500.25",
    "net_contribution": "1999.75", "version": 2,
    "permissions": {"read": true, "record_clawback": true}
  },
  "clawbacks": [{"id": 4, "commission_transaction_id": 5, "amount": "500.25", "reason": "Customer refund", "recorded_by": 2, "version": 1, "permissions": {"read": true}}]
}
```

POST returns 201 with `{clawback, commission}`. It increments the commission version, leaves allocation and ledger rows unchanged, and audits the event. The cumulative clawback may not exceed the commission amount. It accepts only AED commissions. Validation failures use `422 {"message":"...","errors":{"field":["..."]}}`; fields include `expected_version` (missing or stale), `amount` (invalid, zero or over the remaining commission amount), `reason`, and `commission` (non-AED). No external provider or payment transfer is invoked.
