export type Commission = {
    id: number;
    broker_id: number | null;
    broker_name: string | null;
    team_id: number | null;
    team_name: string | null;
    commission_amount: string;
    currency: string | null;
    allocation_status: string | null;
    net_company: string | null;
    agent_payable: string | null;
    clawback_total: string | null;
    net_contribution: string | null;
    version: number;
    permissions: { read: boolean; record_clawback: boolean };
};
export type Clawback = {
    id: number;
    commission_transaction_id: number;
    amount: string;
    reason: string;
    recorded_by: number | null;
    version: number;
    created_at?: string;
};
export type TeamSummary = {
    team_id: number | null;
    team_name: string | null;
    total_commissions: number;
    gross_commission: string;
    clawback_total: string;
    net_contribution: string | null;
};
export type ClawbackForm = { amount: string | number; reason: string };

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';

const cents = (value: string | null | undefined): number =>
    Math.round(Number(value ?? 0) * 100);

/** What can still be clawed back: the commission minus clawbacks already recorded, in AED cents. */
export function remainingCents(
    commission: Pick<Commission, 'commission_amount' | 'clawback_total'>,
): number {
    return (
        cents(commission.commission_amount) - cents(commission.clawback_total)
    );
}

export function remainingText(
    commission: Pick<Commission, 'commission_amount' | 'clawback_total'>,
): string {
    return (Math.max(remainingCents(commission), 0) / 100).toFixed(2);
}

/** Local check that matches the server rule; null means the amount is acceptable. */
export function clawbackError(
    commission: Pick<Commission, 'commission_amount' | 'clawback_total'>,
    form: ClawbackForm,
): 'amount' | 'range' | 'reason' | null {
    const amount = text(form.amount);
    if (!/^\d+(\.\d{1,2})?$/.test(amount) || cents(amount) <= 0) {
        return 'amount';
    }
    if (cents(amount) > remainingCents(commission)) {
        return 'range';
    }

    return text(form.reason) === '' ? 'reason' : null;
}

export function clawbackBody(
    commission: Pick<Commission, 'version'>,
    form: ClawbackForm,
): Record<string, unknown> {
    return {
        expected_version: commission.version,
        amount: text(form.amount),
        reason: text(form.reason),
    };
}

/** Only AED commissions accept clawbacks. */
export function canClawback(commission: Commission): boolean {
    return (
        commission.permissions.record_clawback &&
        (commission.currency ?? 'AED') === 'AED' &&
        remainingCents(commission) > 0
    );
}

export function brokerageQuery(teamId: string, page = 1): string {
    const params = new URLSearchParams();
    if (teamId) {
        params.set('team_id', teamId);
    }
    if (page > 1) {
        params.set('page', String(page));
    }

    return params.toString();
}

export function money(
    amount: string | null | undefined,
    currency = 'AED',
): string {
    return amount === null || amount === undefined
        ? '—'
        : `${currency} ${amount}`;
}

export function teamName(name: string | null): string {
    return name ?? 'Unassigned';
}

export function isNegative(amount: string | null | undefined): boolean {
    return amount !== null && amount !== undefined && Number(amount) < 0;
}
