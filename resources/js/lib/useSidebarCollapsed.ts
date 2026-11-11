import { useCallback, useSyncExternalStore } from 'react';

const STORAGE_KEY = 'artfct:sidebar-collapsed';
const CHANGE_EVENT = 'artfct:sidebar-collapsed-change';

/** Holds the choice when storage is blocked, so the toggle still works. */
let memoryCollapsed: boolean | null = null;

function readCollapsed(): boolean {
    if (memoryCollapsed !== null) {
        return memoryCollapsed;
    }

    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function subscribe(onChange: () => void): () => void {
    // Another tab changed the saved value: it wins over this tab's memory.
    const onStorage = () => {
        memoryCollapsed = null;
        onChange();
    };

    window.addEventListener('storage', onStorage);
    window.addEventListener(CHANGE_EVENT, onChange);

    return () => {
        window.removeEventListener('storage', onStorage);
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
        const next = !readCollapsed();
        memoryCollapsed = next;

        try {
            window.localStorage.setItem(STORAGE_KEY, next ? '1' : '0');
        } catch {
            // Storage can be blocked; the choice then lasts until reload.
        }

        window.dispatchEvent(new Event(CHANGE_EVENT));
    }, []);

    return [collapsed, toggle];
}
