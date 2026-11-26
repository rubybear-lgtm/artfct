import { useEffect } from 'react';

/**
 * Switches on the Oxblood & Bone palette (see `.app-theme` in app.css) while a
 * signed-in or account screen is mounted, so the marketing pages keep
 * their own look. Set on <html> so portals inherit it.
 */
export function useAppTheme(): void {
    useEffect(() => {
        const root = document.documentElement;
        root.classList.add('app-theme');

        return () => root.classList.remove('app-theme');
    }, []);
}
