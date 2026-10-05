export type ImportStep = 1 | 2 | 3 | 4;
export type ImportBatchState = {
    committed_at: string | null;
    summary: unknown;
} | null;

export const STEPS: { step: ImportStep; label: string }[] = [
    { step: 1, label: 'Source file and settings' },
    { step: 2, label: 'Field mapping' },
    { step: 3, label: 'Duplicate control' },
    { step: 4, label: 'Result' },
];

/** Where to open the wizard: finished batches show their result, open ones resume at mapping. */
export function initialStep(batch: ImportBatchState): ImportStep {
    if (!batch) {
        return 1;
    }

    return batch.committed_at || batch.summary ? 4 : 2;
}

function normalise(header: string): string {
    return header
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '');
}

const ALIASES: Record<string, string> = {
    name: 'full_name',
    lead_name: 'full_name',
    full_name: 'full_name',
    mobile: 'phone',
    work_phone: 'phone',
    phone_number: 'phone',
    e_mail: 'email',
    work_e_mail: 'email',
    email_address: 'email',
    organisation: 'company',
    company_name: 'company',
};

/** Best-guess target for a CSV header; empty string means "skip column". */
export function guessTarget(header: string, targets: string[]): string {
    const key = normalise(header);
    if (targets.includes(key)) {
        return key;
    }
    const alias = ALIASES[key];

    return alias && targets.includes(alias) ? alias : '';
}

/** Guesses each header once, so two columns never claim the same field. */
export function guessMapping(
    headers: string[],
    targets: string[],
): Record<string, string> {
    const used = new Set<string>();
    const mapping: Record<string, string> = {};
    for (const header of headers) {
        const guess = guessTarget(header, targets);
        mapping[header] = guess && !used.has(guess) ? guess : '';
        if (mapping[header]) {
            used.add(guess);
        }
    }

    return mapping;
}

export function hasNameMapping(mapping: Record<string, string>): boolean {
    const targets = Object.values(mapping);

    return (
        targets.includes('full_name') ||
        (targets.includes('first_name') && targets.includes('last_name'))
    );
}

/** Targets claimed by more than one column. */
export function duplicateTargets(mapping: Record<string, string>): string[] {
    const seen = new Set<string>();
    const dupes = new Set<string>();
    for (const target of Object.values(mapping)) {
        if (!target) {
            continue;
        }
        if (seen.has(target)) {
            dupes.add(target);
        }
        seen.add(target);
    }

    return [...dupes];
}

export function sampleValues(
    preview: Record<string, string>[],
    header: string,
    count = 3,
): string[] {
    return preview
        .map((row) => (row[header] ?? '').trim())
        .filter((value) => value !== '')
        .slice(0, count);
}

export function errorMessages(
    messages: string[] | Record<string, string[]>,
): string {
    return (
        Array.isArray(messages) ? messages : Object.values(messages).flat()
    ).join('; ');
}

export function targetLabel(
    target: string,
    customNames: Record<string, string>,
): string {
    if (target.startsWith('custom:')) {
        return customNames[target.slice(7)] ?? target.slice(7);
    }

    return target.replaceAll('_', ' ');
}
