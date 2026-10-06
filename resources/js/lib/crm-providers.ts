export type ProviderSettings = {
    sender?: string | null;
    label?: string | null;
    print_title?: string | null;
    terms?: string | null;
    daily_limit?: number | null;
};
export type ProviderRecord = {
    id: number;
    name: string;
    capability: string;
    provider: string | null;
    active: boolean | number;
    settings: ProviderSettings | null;
    version: number;
};
export type ProviderForm = {
    name: string;
    capability: string;
    provider: string;
    sender: string;
    label: string;
    print_title: string;
    terms: string;
    daily_limit: string;
};

export const CAPABILITY_LABELS: Record<string, string> = {
    sms: 'SMS',
    payment: 'Payment',
    accounting_export: 'Accounting export',
    email: 'Email',
    whatsapp: 'WhatsApp',
    property_portal: 'Property portal',
    signature: 'E-signature',
};

export function capabilityLabel(capability: string): string {
    return CAPABILITY_LABELS[capability] ?? capability.replaceAll('_', ' ');
}

export function providerForm(
    capabilities: string[],
    record?: ProviderRecord,
): ProviderForm {
    const settings = record?.settings ?? {};

    return {
        name: record?.name ?? '',
        capability: record?.capability ?? capabilities[0] ?? '',
        provider: record?.provider ?? '',
        sender: settings.sender ?? '',
        label: settings.label ?? '',
        print_title: settings.print_title ?? '',
        terms: settings.terms ?? '',
        daily_limit:
            settings.daily_limit === null || settings.daily_limit === undefined
                ? ''
                : String(settings.daily_limit),
    };
}

/** Profiles stay inactive; blank settings are left out so the server stores only what was typed. */
export function providerBody(
    form: ProviderForm,
    version?: number,
): Record<string, unknown> {
    const settings: Record<string, unknown> = {};
    for (const key of ['sender', 'label', 'print_title', 'terms'] as const) {
        const value = form[key].trim();
        if (value) {
            settings[key] = value;
        }
    }
    const limit = String(form.daily_limit).trim();
    if (limit !== '') {
        settings.daily_limit = Number(limit);
    }

    return {
        ...(version !== undefined ? { expected_version: version } : {}),
        name: form.name.trim(),
        capability: form.capability,
        provider: form.provider.trim() || null,
        active: false,
        settings,
    };
}

export function settingsSummary(settings: ProviderSettings | null): string {
    if (!settings) {
        return '';
    }

    return [settings.label, settings.sender, settings.print_title]
        .filter(
            (part): part is string => typeof part === 'string' && part !== '',
        )
        .join(' · ');
}
