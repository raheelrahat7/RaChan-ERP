import { useLocale } from '@/composables/useLocale';
import {
    formatCompact,
    formatDate,
    formatMoney,
    formatNumber,
} from '@/lib/format';
import type { DateOptions, MoneyOptions, NumericInput } from '@/lib/format';

export function useFormat() {
    const { locale } = useLocale();

    return {
        money: (
            value: NumericInput,
            currency = 'AED',
            options: MoneyOptions = {},
        ): string => formatMoney(value, currency, locale.value, options),
        compact: (value: NumericInput): string =>
            formatCompact(value, locale.value),
        number: (value: NumericInput, decimals = 0): string =>
            formatNumber(value, decimals),
        date: (
            value: string | Date | null | undefined,
            options: DateOptions = {},
        ): string => formatDate(value, locale.value, options),
    };
}
