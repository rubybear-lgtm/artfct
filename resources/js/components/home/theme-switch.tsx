import { useTheme } from '@/lib/theme';
import type { Theme } from '@/lib/theme';

const NEXT: Record<Theme, string> = {
    system: 'light',
    light: 'dark',
    dark: 'the system setting',
};

const PATHS: Record<Theme, string> = {
    system: 'M4 5h16v11H4zM9 20h6M12 16v4',
    light: 'M12 8a4 4 0 100 8 4 4 0 000-8zM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
    dark: 'M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z',
};

/** Cycles system, light and dark. Shows the current setting as an icon. */
export function ThemeSwitch() {
    const [theme, cycle] = useTheme();

    return (
        <button
            type="button"
            className="theme-switch"
            onClick={cycle}
            aria-label={`Theme: ${theme}. Switch to ${NEXT[theme]}.`}
            title={`Theme: ${theme}. Click for ${NEXT[theme]}.`}
            data-testid="landing-theme-switch"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d={PATHS[theme]} />
            </svg>
        </button>
    );
}
