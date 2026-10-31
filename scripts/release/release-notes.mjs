/**
 * Release tag + notes helpers for the production release Action (RUB-517).
 */

/**
 * @param {Date} date
 * @returns {string}
 */
export function formatReleaseTag(date) {
    const year = date.getUTCFullYear().toString().padStart(4, '0');
    const month = (date.getUTCMonth() + 1).toString().padStart(2, '0');
    const day = date.getUTCDate().toString().padStart(2, '0');
    const hour = date.getUTCHours().toString().padStart(2, '0');
    const minute = date.getUTCMinutes().toString().padStart(2, '0');
    return `release-${year}${month}${day}-${hour}${minute}`;
}

/**
 * @param {{
 *   previousTag: string | null,
 *   headSha: string,
 *   now?: Date,
 *   listMergedPrs: (range: { fromRef: string | null, toSha: string }) => Array<{
 *     number: number,
 *     title: string,
 *     url: string,
 *   }>,
 * }} options
 */
export function buildReleaseNotes(options) {
    const now = options.now ?? new Date();
    const tagName = formatReleaseTag(now);
    const prs = options.listMergedPrs({
        fromRef: options.previousTag,
        toSha: options.headSha,
    });

    const lines = [
        `## Production release \`${tagName}\``,
        '',
        `Commit: \`${options.headSha}\``,
        options.previousTag
            ? `Previous release: \`${options.previousTag}\``
            : 'Previous release: none (first annotated release tag).',
        '',
        '### Pull requests',
        '',
    ];

    if (prs.length === 0) {
        lines.push('_No merged pull requests found in this range._');
    } else {
        for (const pr of prs) {
            lines.push(`- #${pr.number} ${pr.title} (${pr.url})`);
        }
    }

    lines.push('');

    return {
        tagName,
        body: lines.join('\n'),
        prs,
    };
}
