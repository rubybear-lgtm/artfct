import { useCallback, useSyncExternalStore } from 'react';

export type Theme = 'system' | 'light' | 'dark';

const STORAGE_KEY = 'artfct-theme';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const CYCLE: Record<Theme, Theme> = {
    system: 'light',
    light: 'dark',
    dark: 'system',
};
const LABEL: Record<Theme, string> = { system: 'sys', light: '☀', dark: '☾' };
const TITLE: Record<Theme, string> = {
    system: 'system theme — click for light',
    light: 'light theme — click for dark',
    dark: 'dark theme — click for system',
};

const listeners = new Set<() => void>();

function readTheme(): Theme {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
        return 'system';
    }
}

function applyTheme(theme: Theme): void {
    const root = document.documentElement;

    if (theme === 'system') {
        root.removeAttribute('data-theme');
    } else {
        root.setAttribute('data-theme', theme);
    }
}

export function setTheme(theme: Theme): void {
    applyTheme(theme);

    try {
        if (theme === 'system') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, theme);
        }
    } catch {
        // Ignore write errors (e.g. Safari Private Mode)
    }

    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    window.addEventListener('storage', listener);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', listener);
    };
}

/**
 * The chosen theme, shared by every component that reads it. The inline script
 * in app.blade.php applies it before first paint; this keeps it in sync after.
 */
export function useTheme(): [Theme, () => void, (theme: Theme) => void] {
    const theme = useSyncExternalStore<Theme>(
        subscribe,
        readTheme,
        () => 'system',
    );

    const cycle = useCallback(() => setTheme(CYCLE[readTheme()]), []);

    return [theme, cycle, setTheme];
}

export function ThemeToggle() {
    const [theme, cycle] = useTheme();

    return (
        <button
            className="theme-toggle"
            onClick={cycle}
            title={TITLE[theme]}
            style={{
                position: 'fixed',
                bottom: '1.5rem',
                right: '1.5rem',
                fontFamily: MONO,
                fontSize: '13px',
                background: 'var(--surface-muted)',
                border: '1px solid var(--ink-quiet)',
                color: 'var(--ink)',
                cursor: 'pointer',
                padding: '0.4rem 0.75rem',
                letterSpacing: '0.05em',
                boxShadow:
                    '0 2px 8px color-mix(in srgb, var(--ink) 15%, transparent)',
                transition:
                    'border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease',
                zIndex: 50,
            }}
        >
            {LABEL[theme]}
        </button>
    );
}
