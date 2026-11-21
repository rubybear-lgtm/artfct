import { useLocalDate } from '@/lib/local-date';

interface LocalDateProps {
    value: string | null | undefined;
    options?: Intl.DateTimeFormatOptions;
    /** Shown in place of a missing or invalid date. Nothing is shown when omitted. */
    fallback?: string;
    /** Text put before a date that is shown, so a separator never stands alone. */
    prefix?: string;
}

/** A date in the reader's own time zone and locale; see `useLocalDate`. For use inside lists, where a hook cannot be called. */
export default function LocalDate({
    value,
    options,
    fallback,
    prefix = '',
}: LocalDateProps) {
    const text = useLocalDate(value, options);

    if (text === null) {
        return fallback ?? null;
    }

    return `${prefix}${text}`;
}
