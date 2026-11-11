import { useCallback, useSyncExternalStore } from 'react';

const STORAGE_KEY = 'artfct:sidebar-collapsed';
const CHANGE_EVENT = 'artfct:sidebar-collapsed-change';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function subscribe(onChange: () => void): () => void {
    window.addEventListener('storage', onChange);
    window.addEventListener(CHANGE_EVENT, onChange);

    return () => {
        window.removeEventListener('storage', onChange);
        window.removeEventListener(CHANGE_EVENT, onChange);
    };
}

/**
 * Whether the desktop sidebar is collapsed to its icon rail, remembered per
 * browser. Server and first client render report expanded, so markup matches.
 */
export function useSidebarCollapsed(): [boolean, () => void] {
    const collapsed = useSyncExternalStore(
        subscribe,
        readCollapsed,
        () => false,
    );

    const toggle = useCallback(() => {
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                readCollapsed() ? '0' : '1',
            );
        } catch {
            // Storage can be blocked; the choice then lasts until reload.
        }

        window.dispatchEvent(new Event(CHANGE_EVENT));
    }, []);

    return [collapsed, toggle];
}
