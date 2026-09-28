export type FieldDescription = {
    describedBy: string | undefined;
    invalid: boolean;
};

export function fieldDescription(
    id: string,
    text: { help?: string; error?: string },
): FieldDescription {
    if (text.error) {
        return { describedBy: `${id}-error`, invalid: true };
    }

    return {
        describedBy: text.help ? `${id}-help` : undefined,
        invalid: false,
    };
}
