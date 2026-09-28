# Depreciation policy proposal — accountant review required

Status: **approved and implemented through monthly posting and correction on 2026-09-15.** The system registers individually approved IAS 16 cost-model assets, previews and posts monthly depreciation, reverses posted charges with linked journals, and records disposal dates without automatic disposal journals.

## First classification decision

Do not depreciate every vendor bill marked `capital_asset` automatically. That treatment currently posts to the generic Capital Assets account (1500); it does not establish what the asset is, when it is ready for use, or which accounting standard applies. The accountant should classify each capitalized item as:

1. Owner-used property, plant and equipment (IAS 16), including separately material components where applicable;
2. Investment property held to earn rent or for capital appreciation (IAS 40);
3. Property held for sale in the ordinary course of business, or another category outside this proposal.

This distinction matters particularly in a real-estate ERP: IAS 40 permits either a fair-value or cost model for investment property. The cost model includes depreciation; the fair-value model measures subsequent changes through profit or loss instead. The ERP must **not** assume a depreciation rule for rental properties until the accountant confirms the IAS 40 model. [IFRS IAS 16 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-16-property-plant-and-equipment/); [IFRS IAS 40 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-40-investment-property/).

## Proposed first implementation scope

Limit automated depreciation to individually approved **IAS 16 cost-model assets**. Exclude investment property, inventory, land, intangible assets, right-of-use assets, and assets under construction until their separate policies are approved. An asset register would link to the source vendor bill or opening-balance source reference, preserve the original cost journal, and require an asset class, cost, residual value, useful life, available-for-use date, and component/parent reference where needed. The accountant supplies the useful life and residual value for each asset or approved class; the system supplies **no default rates or lives**.

Use straight-line depreciation only where it reflects the expected pattern of consumption. Calculate each monthly charge by prorating the depreciable amount `(cost − residual value)` over the approved useful life from the available-for-use date; never reduce the carrying amount below the current residual value through routine depreciation. Hold a proposed monthly run for finance-manager review before posting. Each approved run posts **debit Depreciation Expense, credit Accumulated Depreciation** in AED in an open period, with an asset and month reference. Only one active posting is allowed per asset and month; a reversed posting remains in history and permits a corrected repost. Correct posted runs through linked reversing journals, never by editing them. IAS 16 addresses significant components, the start of depreciation, and periodic review of useful life, residual value, and method. [IFRS IAS 16 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-16-property-plant-and-equipment/); [IAS 16 standard text, paragraphs 43–62](https://www.ifrs.org/content/dam/ifrs/publications/pdf-standards/english/2022/issued/part-a/ias-16-property-plant-and-equipment.pdf).

At each year end, prompt for a documented review of useful life, residual value, method, and whether an impairment assessment is needed. Apply approved estimate changes prospectively rather than silently recalculating posted periods. Disposals, impairments, revaluations, and transfers between asset categories should remain **manual journal and accountant-review work** until separate rules are approved. The first implementation should record a disposal date and stop future depreciation, but should not automatically post sale proceeds, gain/loss, or derecognition. [IFRS IAS 16 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-16-property-plant-and-equipment/); [IFRS IAS 40 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-40-investment-property/).

## Decisions for the accountant

1. Confirm the reporting framework and which asset classes belong to IAS 16, IAS 40, inventory, or another standard. For IAS 40 investment property, confirm cost versus fair-value model before any automation.
2. Confirm whether straight-line with monthly, day-prorated charges is suitable for each IAS 16 class. Supply approved useful lives, residual-value guidance, significant-component thresholds, and any capitalization threshold; the ERP will not invent these values.
3. Confirm whether monthly depreciation requires finance-manager approval and whether a separate reviewer is required for asset setup and estimate changes.
4. Confirm treatment of existing Capital Assets (1500) vendor-bill postings and opening balances. The proposal does **not** reclassify or depreciate them automatically.
5. Provide a separate disposal/impairment/transfer policy and any tax-versus-financial-accounting differences before those postings are automated.

Once these decisions are signed off and the product owner approves the resulting rule, implementation can add the asset register, monthly preview/approval/posting, reversal workflow, and focused ledger/reporting tests.
