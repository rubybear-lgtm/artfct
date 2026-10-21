export type AgentKind = 'terminal' | 'editor' | 'chat' | 'other';

/** A connectable agent as the /connect hub and "Other agents" list show it. */
export interface AgentCard {
    id: string;
    label: string;
    kind: AgentKind;
    method: string;
    summary: string;
    maker: string | null;
    icon: string | null;
}

export const AGENT_GROUPS: { kind: AgentKind; title: string; note: string }[] =
    [
        {
            kind: 'terminal',
            title: 'Coding agents',
            note: 'Agents you run in a terminal.',
        },
        {
            kind: 'editor',
            title: 'Editors',
            note: 'Agents built into your code editor.',
        },
        {
            kind: 'chat',
            title: 'Chat apps',
            note: 'Claude and ChatGPT, on the web, desktop or phone.',
        },
        {
            kind: 'other',
            title: 'Everything else',
            note: 'Any agent that can add a remote server.',
        },
    ];

/** "Other tools" reads better as a card title the other way round. */
export function agentName(label: string): string {
    return label === 'Other tools' ? 'Any other agent' : label;
}
