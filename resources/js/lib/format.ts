export type AppLocale = 'en' | 'ar';

export type NumericInput = number | string | null | undefined;

export type MoneyOptions = { compact?: boolean; decimals?: number };

export type DateOptions = { withTime?: boolean; timeZone?: string };

export const EMPTY_VALUE = '—';

const ARABIC_CURRENCY_SYMBOLS: Record<string, string> = {
    AED: 'د.إ',
    SAR: 'ر.س',
    QAR: 'ر.ق',
    KWD: 'د.ك',
    BHD: 'د.ب',
    OMR: 'ر.ع',
};

const COMPACT_UNITS: Record<AppLocale, [number, string][]> = {
    en: [
        [1e3, 'K'],
        [1e6, 'M'],
        [1e9, 'B'],
    ],
    ar: [
        [1e3, ' ألف'],
        [1e6, ' مليون'],
        [1e9, ' مليار'],
    ],
};

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
const SQL_DATETIME = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}(?::\d{2})?)$/;

export function toNumber(value: NumericInput): number | null {
    if (value === null || value === undefined) {
        return null;
    }
    if (typeof value === 'string' && value.trim() === '') {
        return null;
    }
    const number = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(number) ? number : null;
}

export function formatNumber(value: NumericInput, decimals = 0): string {
    const number = toNumber(value);
    if (number === null) {
        return EMPTY_VALUE;
    }
    const rounded = Number(number.toFixed(decimals)) || 0;

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(rounded);
}

function trimZeros(text: string): string {
    return text.includes('.') ? text.replace(/\.?0+$/, '') : text;
}

function compactDigits(scaled: number): string {
    const size = Math.abs(scaled);
    const digits = size >= 100 ? 0 : size >= 10 ? 1 : 2;

    return trimZeros(scaled.toFixed(digits));
}

export function formatCompact(
    value: NumericInput,
    locale: AppLocale = 'en',
): string {
    const number = toNumber(value);
    if (number === null) {
        return EMPTY_VALUE;
    }
    const units = COMPACT_UNITS[locale];
    let index = -1;
    for (let i = units.length - 1; i >= 0; i--) {
        if (Math.abs(number) >= units[i][0]) {
            index = i;
            break;
        }
    }
    if (index === -1) {
        return formatNumber(number, Number.isInteger(number) ? 0 : 2);
    }
    let text = compactDigits(number / units[index][0]);
    if (Math.abs(Number(text)) >= 1000 && index < units.length - 1) {
        index += 1;
        text = compactDigits(number / units[index][0]);
    }

    return `${text}${units[index][1]}`;
}

export function currencySymbol(currency: string, locale: AppLocale): string {
    return locale === 'ar'
        ? (ARABIC_CURRENCY_SYMBOLS[currency] ?? currency)
        : currency;
}

export function formatMoney(
    value: NumericInput,
    currency = 'AED',
    locale: AppLocale = 'en',
    options: MoneyOptions = {},
): string {
    const number = toNumber(value);
    if (number === null) {
        return EMPTY_VALUE;
    }
    const amount = options.compact
        ? formatCompact(number, locale)
        : formatNumber(number, options.decimals ?? 2);
    const symbol = currencySymbol(currency, locale);

    return locale === 'ar' ? `${amount} ${symbol}` : `${symbol} ${amount}`;
}

function parts(
    date: Date,
    tag: string,
    options: Intl.DateTimeFormatOptions,
): Record<string, string> {
    return Object.fromEntries(
        new Intl.DateTimeFormat(tag, options)
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );
}

export function formatDate(
    value: string | Date | null | undefined,
    locale: AppLocale = 'en',
    options: DateOptions = {},
): string {
    if (value === null || value === undefined || value === '') {
        return EMPTY_VALUE;
    }
    const dateOnly = typeof value === 'string' && DATE_ONLY.test(value);
    let date: Date;
    if (typeof value === 'string') {
        const sql = SQL_DATETIME.exec(value);
        const iso = dateOnly
            ? `${value}T00:00:00Z`
            : sql
              ? `${sql[1]}T${sql[2]}Z`
              : value;
        date = new Date(iso);
    } else {
        date = value;
    }
    if (Number.isNaN(date.getTime())) {
        return EMPTY_VALUE;
    }
    const timeZone = dateOnly ? 'UTC' : options.timeZone;
    const day = parts(date, locale === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US', {
        day: 'numeric',
        month: locale === 'ar' ? 'long' : 'short',
        year: 'numeric',
        timeZone,
    });
    let text = `${day.day} ${day.month} ${day.year}`;
    if (options.withTime && !dateOnly) {
        const time = parts(date, 'en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone,
        });
        text += `${locale === 'ar' ? '،' : ','} ${time.hour}:${time.minute}`;
    }

    return text;
}
