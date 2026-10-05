export type FilterField = {
    key: string;
    name: string;
    type: string;
    options: string[] | null;
    operators: string[];
};
export type Condition = {
    field: string;
    operator: string;
    value?: string | number | boolean | string[];
};

const OPERATOR_LABELS: Record<string, string> = {
    equals: 'is',
    not_equals: 'is not',
    contains: 'contains',
    gte: 'at least',
    lte: 'at most',
    empty: 'is empty',
    not_empty: 'is not empty',
};

export function operatorLabel(operator: string): string {
    return OPERATOR_LABELS[operator] ?? operator;
}

/** Empty / not-empty compare nothing, so they carry no value. */
export function needsValue(operator: string): boolean {
    return operator !== 'empty' && operator !== 'not_empty';
}

export function fieldOf(
    fields: FilterField[],
    key: string,
): FilterField | undefined {
    return fields.find((field) => field.key === key);
}

export function newCondition(fields: FilterField[]): Condition | null {
    const field = fields[0];

    return field ? { field: field.key, operator: field.operators[0] } : null;
}

/** Switching field resets the operator and value so they always fit the new field. */
export function changeField(
    fields: FilterField[],
    condition: Condition,
    key: string,
): Condition {
    const field = fieldOf(fields, key);

    return field ? { field: key, operator: field.operators[0] } : condition;
}

/** A condition is ready to send once it names a known field/operator and (if needed) a value. */
export function isComplete(
    fields: FilterField[],
    condition: Condition,
): boolean {
    const field = fieldOf(fields, condition.field);
    if (!field || !field.operators.includes(condition.operator)) {
        return false;
    }
    if (!needsValue(condition.operator)) {
        return true;
    }
    const value = condition.value;

    return Array.isArray(value)
        ? value.length > 0
        : value !== undefined && value !== '';
}

/** Only complete conditions, with checkbox/number text coerced the way the server expects. */
export function cleanConditions(
    fields: FilterField[],
    conditions: Condition[],
): Condition[] {
    return conditions
        .filter((condition) => isComplete(fields, condition))
        .map((condition) => {
            if (!needsValue(condition.operator)) {
                return { field: condition.field, operator: condition.operator };
            }
            const type = fieldOf(fields, condition.field)?.type;
            const value = condition.value;
            if (type === 'checkbox') {
                return {
                    ...condition,
                    value: value === true || value === 'true',
                };
            }
            if (
                ['number', 'currency', 'user'].includes(type ?? '') &&
                typeof value === 'string'
            ) {
                return { ...condition, value: Number(value) };
            }

            return condition;
        });
}

/** Query-string pairs for the export link (arrays use Laravel's bracket style). */
export function exportQuery(filters: {
    pipeline_id?: number | string | null;
    q?: string | null;
    custom_filters?: Condition[];
}): string {
    const params = new URLSearchParams();
    if (filters.pipeline_id) {
        params.set('pipeline_id', String(filters.pipeline_id));
    }
    if (filters.q) {
        params.set('q', filters.q);
    }
    (filters.custom_filters ?? []).forEach((condition, index) => {
        params.set(`custom_filters[${index}][field]`, condition.field);
        params.set(`custom_filters[${index}][operator]`, condition.operator);
        if (condition.value !== undefined) {
            const values = Array.isArray(condition.value)
                ? condition.value
                : [condition.value];
            values.forEach((value, position) =>
                params.set(
                    Array.isArray(condition.value)
                        ? `custom_filters[${index}][value][${position}]`
                        : `custom_filters[${index}][value]`,
                    String(value),
                ),
            );
        }
    });

    return params.toString();
}
