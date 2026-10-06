export type DealInvoiceLink = {
    id: number;
    reference: string;
    status: string;
    total: string | number;
    currency: string;
    received?: string | number;
    outstanding?: string | number;
};
export type DealCommissionLink = {
    id: number;
    status: string;
    commission_amount: string | number;
    currency: string;
    paid_on: string | null;
};
export type FinancialRecords = {
    invoices: DealInvoiceLink[];
    commissions: DealCommissionLink[];
};

export function emptyFinancials(
    records: Partial<FinancialRecords> | null | undefined,
): FinancialRecords {
    return {
        invoices: records?.invoices ?? [],
        commissions: records?.commissions ?? [],
    };
}

export function linkBody(input: {
    kind: 'invoice' | 'commission';
    recordId: string;
    expectedVersion: number;
    remove?: boolean;
}): Record<string, unknown> | null {
    const id = Number(input.recordId);
    if (!Number.isInteger(id) || id < 1) {
        return null;
    }

    return {
        kind: input.kind,
        record_id: id,
        expected_version: input.expectedVersion,
        ...(input.remove ? { remove: true } : {}),
    };
}

/** Sum of the outstanding invoice balances, two decimals; null when none are reported. */
export function outstandingTotal(invoices: DealInvoiceLink[]): string | null {
    const values = invoices
        .map((invoice) => invoice.outstanding)
        .filter((value): value is string | number => value !== undefined);
    if (!values.length) {
        return null;
    }
    const cents = values.reduce<number>(
        (sum, value) => sum + Math.round(Number(value) * 100),
        0,
    );

    return (cents / 100).toFixed(2);
}
