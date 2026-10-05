/** Plain-language names for the tool ids the artifacts carry. */
export const AI_TOOL_NAMES: Record<string, string> = {
    'claude-code': 'Claude Code',
    cursor: 'Cursor',
    codex: 'Codex',
    copilot: 'Copilot',
    chatgpt: 'ChatGPT',
    claude: 'Claude',
};

/**
 * A tool id as a name the user recognises. A tool nothing names yet is
 * rendered as readable words rather than the raw id, and a missing id is
 * simply unknown.
 */
export function aiToolName(id: string | null | undefined): string {
    if (!id) {
        return 'Unknown tool';
    }

    return (
        AI_TOOL_NAMES[id] ??
        id
            .split(/[-_]/)
            .filter(Boolean)
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ')
    );
}
