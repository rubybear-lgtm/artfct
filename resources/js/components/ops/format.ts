const units = ['B', 'KB', 'MB', 'GB', 'TB'] as const;

export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 B';
    }

    const index = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** index;

    return `${index === 0 ? value : value.toFixed(1)} ${units[index]}`;
}

/** Turns a snake_case or kebab-case value into a sentence-case label. */
export function humanize(value: string): string {
    const spaced = value.replace(/[_-]+/g, ' ').trim();

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

export function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export function formatDate(value: string | null): string {
    if (!value) {
        return 'None';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString();
}

export function formatNumber(value: number): string {
    return value.toLocaleString();
}
