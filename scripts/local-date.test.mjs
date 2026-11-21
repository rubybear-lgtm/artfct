// The pure formatters behind every date and number the app shows
// (resources/js/lib/local-date.ts). They take the reader explicitly, so they are
// deterministic in any time zone. Run: npm run test:local-date
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    EN_US_READER,
    formatCalendarDay,
    formatLocalDate,
    formatLocalNumber,
} from '../resources/js/lib/local-date.ts';

const UTC_EN = { locale: 'en-US', timeZone: 'UTC' };
const AUCKLAND_EN = { locale: 'en-US', timeZone: 'Pacific/Auckland' };
const UTC_DE = { locale: 'de-DE', timeZone: 'UTC' };
// 20:00 UTC on the 12th is already the 13th in Auckland.
const INSTANT = '2026-11-12T20:00:00Z';

test('a missing or invalid date is null, and zero is not a date', () => {
    assert.equal(formatLocalDate(null, UTC_EN), null);
    assert.equal(formatLocalDate(undefined, UTC_EN), null);
    assert.equal(formatLocalDate('', UTC_EN), null);
    assert.equal(formatLocalDate('not a date', UTC_EN), null);
    assert.equal(formatLocalDate(0, UTC_EN), null);
});

test('the default is the medium date, in the reader\'s own time zone and locale', () => {
    assert.equal(formatLocalDate(INSTANT, UTC_EN), 'Nov 12, 2026');
    assert.equal(formatLocalDate(INSTANT, AUCKLAND_EN), 'Nov 13, 2026');
    assert.equal(formatLocalDate(INSTANT, UTC_DE), '12.11.2026');
});

test('empty options give the bare short date, as toLocaleDateString() does', () => {
    assert.equal(formatLocalDate(INSTANT, UTC_EN, {}), '11/12/2026');
    assert.equal(formatLocalDate(INSTANT, AUCKLAND_EN, {}), '11/13/2026');
});

test('time shows the date and the time, as toLocaleString() does', () => {
    assert.match(
        formatLocalDate(INSTANT, UTC_EN, {}, { time: true }),
        /^11\/12\/2026, 8:00:00\sPM$/,
    );
    assert.match(
        formatLocalDate(
            INSTANT,
            UTC_EN,
            { dateStyle: 'medium', timeStyle: 'short' },
            { time: true },
        ),
        /^Nov 12, 2026(,| at) 8:00\sPM$/,
    );
});

test('a unix time in milliseconds works, as the billing invoices pass', () => {
    assert.equal(
        formatLocalDate(Date.parse(INSTANT), UTC_EN),
        'Nov 12, 2026',
    );
});

test('an explicit time zone option wins over the reader\'s', () => {
    assert.equal(
        formatLocalDate(INSTANT, UTC_EN, {
            dateStyle: 'medium',
            timeZone: 'Pacific/Auckland',
        }),
        'Nov 13, 2026',
    );
});

test('a locale in the config wins over the reader\'s, while the time zone stays theirs', () => {
    const short = { month: 'short', day: 'numeric' };

    assert.equal(
        formatLocalDate(INSTANT, UTC_DE, short, { locale: 'en-US' }),
        'Nov 12',
    );
    assert.equal(
        formatLocalDate(
            INSTANT,
            { locale: 'de-DE', timeZone: 'Pacific/Auckland' },
            short,
            { locale: 'en-US' },
        ),
        'Nov 13',
    );
});

test('numbers follow the reader\'s locale, and EN_US_READER is always en-US', () => {
    assert.equal(formatLocalNumber(1234567, UTC_EN), '1,234,567');
    assert.equal(formatLocalNumber(1234567, UTC_DE), '1.234.567');
    assert.equal(formatLocalNumber(1234567, EN_US_READER), '1,234,567');
    assert.equal(
        formatLocalNumber(0.256, UTC_EN, { style: 'percent' }),
        '26%',
    );
    assert.deepEqual(EN_US_READER, { locale: 'en-US', timeZone: undefined });
});

test('a calendar day reads the same whatever the time zone', () => {
    const long = { day: 'numeric', month: 'long', year: 'numeric' };

    assert.equal(
        formatCalendarDay('2026-10-04', 'en-GB', long),
        '4 October 2026',
    );
    assert.equal(
        formatCalendarDay('2026-01-01', 'en-GB', long),
        '1 January 2026',
    );
});
