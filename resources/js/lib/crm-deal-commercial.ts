export type DealOption = {
    code: string;
    name: string;
    active: boolean;
    id?: number | null;
};
export type DealCommercialForm = {
    deal_status: string;
    scenario: string;
    gross_commission: string | number;
    co_broker_share: string | number;
    agent_share: string | number;
};
type CommercialSource = {
    deal_status?: string | null;
    scenario?: string | null;
    gross_commission?: string | number | null;
    co_broker_share?: string | number | null;
    agent_share?: string | number | null;
};

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';

export function commercialFields(
    deal?: CommercialSource | null,
): DealCommercialForm {
    return {
        deal_status: deal?.deal_status ?? '',
        scenario: deal?.scenario ?? '',
        gross_commission: deal?.gross_commission ?? '',
        co_broker_share: deal?.co_broker_share ?? '',
        agent_share: deal?.agent_share ?? '',
    };
}

/** Active options, plus the one the deal already holds so editing never forces a change. */
export function optionChoices(
    options: DealOption[],
    current: string | null | undefined,
): DealOption[] {
    return options.filter((option) => option.active || option.code === current);
}

export function optionLabel(
    options: DealOption[],
    code: string | null | undefined,
): string {
    if (!code) {
        return '—';
    }

    return (
        options.find((option) => option.code === code)?.name ??
        code.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())
    );
}

/** The two shares are planning percentages of one commission, so together they cannot pass 100. */
export function sharesTooHigh(
    form: Pick<DealCommercialForm, 'co_broker_share' | 'agent_share'>,
): boolean {
    return (
        (Number(text(form.co_broker_share)) || 0) +
            (Number(text(form.agent_share)) || 0) >
        100
    );
}

const orNull = (value: unknown): string | null => text(value) || null;

/**
 * Body fields for create or update. Money fields are left out when the person
 * may not see amounts, so they are never cleared by accident.
 */
export function commercialPayload(
    form: DealCommercialForm,
    canSeeAmounts: boolean,
): Record<string, unknown> {
    const body: Record<string, unknown> = {
        deal_status: orNull(form.deal_status),
        scenario: orNull(form.scenario),
    };
    if (canSeeAmounts) {
        body.gross_commission = orNull(form.gross_commission);
        body.co_broker_share = orNull(form.co_broker_share);
        body.agent_share = orNull(form.agent_share);
    }

    return body;
}
