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
        [1e12, 'T'],
    ],
    ar: [
        [1e3, ' ألف'],
        [1e6, ' مليون'],
        [1e9, ' مليار'],
        [1e12, ' تريليون'],
    ],
};

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
// Laravel serialises `date` casts as UTC midnight, e.g. 2026-09-14T00:00:00.000000Z.
const DATE_CAST = /^(\d{4}-\d{2}-\d{2})T00:00:00(?:\.0+)?Z$/;
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

// Keeps a leading minus next to its digits inside right-to-left text.
function isolateNegative(text: string, locale: AppLocale): string {
    return locale === 'ar' && text.startsWith('-')
        ? `\u2066${text}\u2069`
        : text;
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
    // Compare the value as it will be shown, so 999.999 becomes 1K, not 1,000.00.
    const shown = Math.abs(Number(number.toFixed(2)));
    let index = -1;
    for (let i = units.length - 1; i >= 0; i--) {
        if (shown >= units[i][0]) {
            index = i;
            break;
        }
    }
    if (index === -1) {
        return isolateNegative(
            formatNumber(number, Number.isInteger(number) ? 0 : 2),
            locale,
        );
    }
    let text = compactDigits(number / units[index][0]);
    if (Math.abs(Number(text)) >= 1000 && index < units.length - 1) {
        index += 1;
        text = compactDigits(number / units[index][0]);
    }

    return `${isolateNegative(text, locale)}${units[index][1]}`;
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
        : isolateNegative(formatNumber(number, options.decimals ?? 2), locale);
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
    const castDate =
        typeof value === 'string' ? DATE_CAST.exec(value)?.[1] : undefined;
    if (castDate) {
        value = castDate;
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

export type StatFigureInput = {
    value: NumericInput;
    currency?: string;
    unit?: string;
    compact?: boolean;
    decimals?: number;
};

export type StatFigure = {
    prefix: string | null;
    figure: string;
    suffix: string | null;
};

export function statFigure(
    input: StatFigureInput,
    locale: AppLocale,
): StatFigure {
    const figure =
        input.compact === false
            ? formatNumber(input.value, input.decimals ?? 0)
            : formatCompact(input.value, locale);
    if (figure === EMPTY_VALUE) {
        return { prefix: null, figure, suffix: null };
    }
    if (input.currency) {
        return locale === 'ar'
            ? {
                  prefix: null,
                  figure,
                  suffix: currencySymbol(input.currency, 'ar'),
              }
            : { prefix: input.currency, figure, suffix: null };
    }

    return { prefix: null, figure, suffix: input.unit ?? null };
}

const RELATIVE_STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 30],
    ['month', 12],
    ['year', Number.POSITIVE_INFINITY],
];

export function formatRelative(
    value: string | Date | null | undefined,
    locale: AppLocale = 'en',
    now: Date = new Date(),
): string {
    if (value === null || value === undefined || value === '') {
        return EMPTY_VALUE;
    }
    const date = typeof value === 'string' ? new Date(value) : value;
    if (Number.isNaN(date.getTime())) {
        return EMPTY_VALUE;
    }
    const formatter = new Intl.RelativeTimeFormat(
        locale === 'ar' ? 'ar-AE-u-nu-latn' : 'en',
        { numeric: 'auto' },
    );
    let amount = (date.getTime() - now.getTime()) / 1000;
    for (const [unit, size] of RELATIVE_STEPS) {
        if (Math.abs(amount) < size) {
            return formatter.format(
                unit === 'second' ? 0 : Math.round(amount),
                unit,
            );
        }
        amount /= size;
    }

    return EMPTY_VALUE;
}

export function formatMonth(value: string, locale: AppLocale = 'en'): string {
    if (!/^\d{4}-\d{2}$/.test(value)) {
        return EMPTY_VALUE;
    }

    return new Intl.DateTimeFormat(
        locale === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US',
        { month: locale === 'ar' ? 'long' : 'short', timeZone: 'UTC' },
    ).format(new Date(`${value}-01T00:00:00Z`));
}
