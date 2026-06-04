import { useCallback, useEffect, useState } from 'react';

export type Theme = 'system' | 'light' | 'dark';

const STORAGE_KEY = 'artfct-theme';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const CYCLE: Record<Theme, Theme> = { system: 'light', light: 'dark', dark: 'system' };
const LABEL: Record<Theme, string> = { system: 'sys', light: '☀', dark: '☾' };
const TITLE: Record<Theme, string> = {
    system: 'system theme — click for light',
    light:  'light theme — click for dark',
    dark:   'dark theme — click for system',
};

export function useTheme(): [Theme, () => void] {
    const [theme, setTheme] = useState<Theme>(() => {
        try {
            return (localStorage.getItem(STORAGE_KEY) as Theme | null) ?? 'system';
        } catch {
            return 'system';
        }
    });

    useEffect(() => {
        const root = document.documentElement;
        if (theme === 'system') {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', theme);
        }
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch {}
    }, [theme]);

    const cycle = useCallback(() => setTheme((t) => CYCLE[t]), []);

    return [theme, cycle];
}

export function ThemeToggle() {
    const [theme, cycle] = useTheme();

    return (
        <button
            onClick={cycle}
            title={TITLE[theme]}
            style={{
                fontFamily: MONO,
                fontSize: '11px',
                background: 'none',
                border: '1px solid var(--sol-base2)',
                color: 'var(--sol-base1)',
                cursor: 'pointer',
                padding: '0.15rem 0.5rem',
                letterSpacing: '0.04em',
                transition: 'border-color 0.15s ease, color 0.15s ease',
                flexShrink: 0,
            }}
        >
            {LABEL[theme]}
        </button>
    );
}
