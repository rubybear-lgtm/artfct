import { useSyncExternalStore } from 'react';

/**
 * The one place that formats a date or a number for a person to read.
 *
 * Every page is rendered first by the SSR server, which does not know the
 * reader's time zone or locale, and then hydrated in the browser. Formatting
 * with the defaults in both places gives two different strings (a date near
 * midnight UTC, a number with another locale's separators), and React refuses
 * to hydrate the page (error 418). So a page never calls `toLocaleString`,
 * `toLocaleDateString` or `Intl.*Format` itself (ESLint enforces it). It asks
 * `useReader()` who is reading and passes that to the formatters below.
 *
 * The server render, and the first render while hydrating, use one fixed
 * reader (UTC, en-US). Right after hydration `useSyncExternalStore` switches
 * to the browser's own time zone and locale.
 */
export interface Reader {
    locale: string | undefined;
    timeZone: string | undefined;
}

/** Module constants, so the snapshots are the same object every call. */
const SERVER_READER: Reader = { locale: 'en-US', timeZone: 'UTC' };
const BROWSER_READER: Reader = { locale: undefined, timeZone: undefined };

/** Nothing to subscribe to: the locale and time zone do not change while a page is open. */
function subscribeToNothing(): () => void {
    return () => undefined;
}

export function useReader(): Reader {
    return useSyncExternalStore(
        subscribeToNothing,
        () => BROWSER_READER,
        () => SERVER_READER,
    );
}

export interface DateConfig {
    /** Show the time as well as the date, as `toLocaleString` does. */
    time?: boolean;
    /** Use this locale for everyone, instead of the reader's. */
    locale?: string;
}

/**
 * `null` for a missing or invalid value. With no options the date is
 * `dateStyle: 'medium'`; pass `{}` for the locale's bare default short form, as
 * `toLocaleDateString()` with no arguments gives.
 */
export function formatLocalDate(
    value: string | number | null | undefined,
    reader: Reader,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
    config: DateConfig = {},
): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const locale = config.locale ?? reader.locale;
    const withZone: Intl.DateTimeFormatOptions = {
        ...options,
        timeZone: options.timeZone ?? reader.timeZone,
    };

    return config.time
        ? date.toLocaleString(locale, withZone)
        : date.toLocaleDateString(locale, withZone);
}

export function formatLocalNumber(
    value: number,
    reader: Reader,
    options?: Intl.NumberFormatOptions,
): string {
    return value.toLocaleString(reader.locale, options);
}

/** A reader whose number format is always en-US, for text that must read the same everywhere. */
export const EN_US_READER: Reader = { locale: 'en-US', timeZone: undefined };

/**
 * A calendar day such as "2026-10-04" (a blog post's date) in a fixed locale.
 * It is parsed and formatted in the same time zone, so it reads the same on the
 * server and in every browser.
 */
export function formatCalendarDay(
    day: string,
    locale: string,
    options: Intl.DateTimeFormatOptions,
): string {
    return new Date(`${day}T00:00:00`).toLocaleDateString(locale, options);
}

export function useLocalDate(
    value: string | number | null | undefined,
    options?: Intl.DateTimeFormatOptions,
    config?: DateConfig,
): string | null {
    return formatLocalDate(value, useReader(), options, config);
}
