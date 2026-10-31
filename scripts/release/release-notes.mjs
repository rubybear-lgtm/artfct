/**
 * Release tag + notes helpers for the production release Action (RUB-517).
 * Stub until tests fail, then implemented.
 */

/**
 * @param {Date} date
 * @returns {string}
 */
export function formatReleaseTag(date) {
    void date;
    throw new Error('formatReleaseTag: not implemented');
}

/**
 * @param {{
 *   previousTag: string | null,
 *   headSha: string,
 *   listMergedPrs: (range: { fromRef: string | null, toSha: string }) => Array<{
 *     number: number,
 *     title: string,
 *     url: string,
 *   }>,
 * }} options
 * @returns {{ tagName: string, body: string, prs: Array<{ number: number, title: string, url: string }> }}
 */
export function buildReleaseNotes(options) {
    void options;
    throw new Error('buildReleaseNotes: not implemented');
}
