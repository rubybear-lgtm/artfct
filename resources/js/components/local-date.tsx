import { formatLocalNumber, useLocalDate, useReader } from '@/lib/local-date';
import type { DateConfig } from '@/lib/local-date';

interface LocalDateProps extends DateConfig {
    value: string | number | null | undefined;
    options?: Intl.DateTimeFormatOptions;
    /** Shown in place of a missing or invalid date. Nothing is shown when omitted. */
    fallback?: string;
    /** Text put before a date that is shown, so a separator never stands alone. */
    prefix?: string;
}

/**
 * A date in the reader's own time zone and locale, with no hydration mismatch.
 * For use where a hook cannot be called (inside a list); see `lib/local-date`.
 */
export default function LocalDate({
    value,
    options,
    fallback,
    prefix = '',
    time,
    locale,
}: LocalDateProps) {
    const text = useLocalDate(value, options, { time, locale });

    if (text === null) {
        return fallback ?? null;
    }

    return `${prefix}${text}`;
}

interface LocalNumberProps {
    value: number;
    options?: Intl.NumberFormatOptions;
}

/** A number with the reader's own separators, with no hydration mismatch. */
export function LocalNumber({ value, options }: LocalNumberProps) {
    return formatLocalNumber(value, useReader(), options);
}
