import { useSyncExternalStore } from 'react';

/** Nothing to subscribe to: the reader's locale and time zone do not change while a page is open. */
function subscribeToNothing(): () => void {
    return () => undefined;
}

function formatDate(
    value: string | null | undefined,
    locale: string | undefined,
    options: Intl.DateTimeFormatOptions,
): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? null
        : date.toLocaleDateString(locale, options);
}

/**
 * A date as the reader should see it, without a hydration mismatch.
 *
 * The server renders every page, and it does not know the reader's time zone
 * or locale, so formatting a date with the defaults there and again in the
 * browser gives two different strings near midnight UTC, and React refuses to
 * hydrate the page (error 418). The server's render therefore uses one fixed
 * form (UTC, en-US). The browser hydrates with that same form, then
 * `useSyncExternalStore` swaps in the reader's own time zone and locale.
 */
export function useLocalDate(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
): string | null {
    return useSyncExternalStore(
        subscribeToNothing,
        () => formatDate(value, undefined, options),
        () => formatDate(value, 'en-US', { ...options, timeZone: 'UTC' }),
    );
}
