import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatCompact,
    formatDate,
    formatMoney,
    formatNumber,
    toNumber,
} from '../../resources/js/lib/format.ts';

await test('money puts the code first in English and the Arabic symbol last', () => {
    assert.equal(formatMoney(212000, 'AED', 'en'), 'AED 212,000.00');
    assert.equal(formatMoney('96500.5', 'AED', 'ar'), '96,500.50 د.إ');
    assert.equal(formatMoney(1500, 'SAR', 'ar'), '1,500.00 ر.س');
    assert.equal(formatMoney(1500, 'USD', 'ar'), '1,500.00 USD');
    assert.equal(formatMoney(-1500, 'AED', 'en'), 'AED -1,500.00');
    assert.equal(formatMoney(1500, 'AED', 'en', { decimals: 0 }), 'AED 1,500');
});

await test('compact figures round cleanly across unit boundaries', () => {
    assert.equal(formatCompact(2418500), '2.42M');
    assert.equal(formatCompact(186200), '186K');
    assert.equal(formatCompact(999999), '1M');
    assert.equal(formatCompact(999_999_999), '1B');
    assert.equal(formatCompact(-1500000), '-1.5M');
    assert.equal(formatCompact(950), '950');
    assert.equal(formatCompact(2418500, 'ar'), '2.42 مليون');
    assert.equal(
        formatMoney(2418500, 'AED', 'ar', { compact: true }),
        '2.42 مليون د.إ',
    );
    assert.equal(
        formatMoney(2418500, 'AED', 'en', { compact: true }),
        'AED 2.42M',
    );
});

await test('missing or invalid values render an em dash, never NaN or -0', () => {
    for (const value of [
        null,
        undefined,
        '',
        '   ',
        'abc',
        Number.NaN,
        Number.POSITIVE_INFINITY,
    ]) {
        assert.equal(formatMoney(value), '—');
        assert.equal(formatNumber(value), '—');
        assert.equal(formatCompact(value), '—');
    }
    assert.equal(toNumber('0'), 0);
    assert.equal(formatNumber(-0.001, 2), '0.00');
    assert.equal(formatMoney(-0.001), 'AED 0.00');
});

await test('dates use Western digits and a fixed day month year order', () => {
    assert.equal(formatDate('2026-09-14', 'en'), '14 Sep 2026');
    assert.equal(formatDate('2026-09-14', 'ar'), '14 سبتمبر 2026');
    assert.equal(
        formatDate('2026-09-14T09:40:00Z', 'en', {
            withTime: true,
            timeZone: 'UTC',
        }),
        '14 Sep 2026, 09:40',
    );
    assert.equal(
        formatDate('2026-09-14T09:40:00Z', 'ar', {
            withTime: true,
            timeZone: 'UTC',
        }),
        '14 سبتمبر 2026، 09:40',
    );
    assert.equal(
        formatDate('2026-09-14 09:40:00', 'en', {
            withTime: true,
            timeZone: 'UTC',
        }),
        '14 Sep 2026, 09:40',
    );
    assert.equal(
        formatDate('2026-09-14', 'en', { withTime: true }),
        '14 Sep 2026',
    );
    assert.equal(formatDate('not a date'), '—');
    assert.equal(formatDate(null), '—');
    assert.equal(formatDate(''), '—');
});

await test('date-only values never shift a day in the viewer timezone', () => {
    assert.equal(formatDate('2026-09-01', 'en'), '1 Sep 2026');
    assert.equal(formatDate('2026-12-31', 'en'), '31 Dec 2026');
});

await test('Laravel date casts at UTC midnight keep their day west of UTC', () => {
    const previous = process.env.TZ;
    process.env.TZ = 'America/New_York';
    try {
        assert.equal(
            formatDate('2026-09-14T00:00:00.000000Z', 'en'),
            '14 Sep 2026',
        );
        assert.equal(
            formatDate('2026-09-14T00:00:00Z', 'ar'),
            '14 سبتمبر 2026',
        );
    } finally {
        process.env.TZ = previous;
    }
});

await test('negative Arabic amounts isolate the number so the minus stays in front', () => {
    assert.equal(formatMoney(-1500, 'AED', 'ar'), '⁦-1,500.00⁩ د.إ');
    assert.equal(formatCompact(-1500000, 'ar'), '⁦-1.5⁩ مليون');
    assert.equal(
        formatMoney(-1500000, 'AED', 'ar', { compact: true }),
        '⁦-1.5⁩ مليون د.إ',
    );
    assert.equal(formatMoney(1500, 'AED', 'ar'), '1,500.00 د.إ');
    assert.equal(formatMoney(-1500, 'AED', 'en'), 'AED -1,500.00');
});
