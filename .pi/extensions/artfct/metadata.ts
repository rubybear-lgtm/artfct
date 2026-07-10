/**
 * HTML metadata extraction for artfct artifacts.
 *
 * Mirrors the logic in mcp-server/src/artifact_crypto.rs:
 * - Extract <title> from head
 * - Extract meta description / og:image
 * - Fall back to first <p> or <img> content
 */

const HEAD_SCAN_LIMIT = 8192;
const DEFAULT_TITLE = "Encrypted artifact";
const DEFAULT_DESCRIPTION = "Encrypted HTML preview on artfct.";
const DEFAULT_THUMBNAIL = "https://artfct.dev/og-image.svg";

/** Extract title from an HTML string. */
export function extractTitle(html: string): string {
  const head = html.slice(0, HEAD_SCAN_LIMIT);
  const lower = head.toLowerCase();

  const tagStart = lower.indexOf("<title");
  if (tagStart === -1) return DEFAULT_TITLE;

  const contentStart = lower.indexOf(">", tagStart) + 1;
  if (contentStart === 0) return DEFAULT_TITLE;

  const contentEnd = lower.indexOf("</title>", contentStart);
  if (contentEnd === -1) return DEFAULT_TITLE;

  const title = head.slice(contentStart, contentEnd).trim();
  return title || DEFAULT_TITLE;
}

/** Extract description from meta tags or first paragraph. */
export function extractDescription(html: string): string {
  const head = html.slice(0, HEAD_SCAN_LIMIT);
  const lower = head.toLowerCase();

  // Try meta description
  for (const needle of ['name="description"', "name='description'"]) {
    const content = extractMetaContent(head, lower, needle);
    if (content) return content;
  }

  // Fall back to first paragraph
  const para = extractFirstParagraph(head, lower);
  if (para) return para;

  // Final fallback to title
  const title = extractTitle(html);
  return title !== DEFAULT_TITLE ? title : DEFAULT_DESCRIPTION;
}

/** Extract thumbnail from og:image or first img src. */
export function extractThumbnail(html: string): string {
  const head = html.slice(0, HEAD_SCAN_LIMIT);
  const lower = head.toLowerCase();

  // Try og:image
  for (const needle of ['property="og:image"', "property='og:image'"]) {
    const content = extractMetaContent(head, lower, needle);
    if (content) return content;
  }

  // Try first img src
  const imgSrc = extractImgSrc(head, lower);
  if (imgSrc) return imgSrc;

  return DEFAULT_THUMBNAIL;
}

function extractMetaContent(head: string, lower: string, needle: string): string | null {
  const index = lower.indexOf(needle);
  if (index === -1) return null;

  const tagStart = lower.lastIndexOf("<meta", index);
  if (tagStart === -1) return null;

  const tagEnd = lower.indexOf(">", index);
  if (tagEnd === -1) return null;

  const tag = head.slice(tagStart, tagEnd);
  return extractAttributeValue(tag, "content");
}

function extractFirstParagraph(head: string, lower: string): string | null {
  const start = lower.indexOf("<p");
  if (start === -1) return null;

  const contentStart = lower.indexOf(">", start) + 1;
  if (contentStart === 0) return null;

  const contentEnd = lower.indexOf("</p>", contentStart);
  if (contentEnd === -1) return null;

  const content = stripTags(head.slice(contentStart, contentEnd)).trim();
  return content || null;
}

function extractImgSrc(head: string, lower: string): string | null {
  const start = lower.indexOf("<img");
  if (start === -1) return null;

  const end = lower.indexOf(">", start);
  if (end === -1) return null;

  const tag = head.slice(start, end);
  return extractAttributeValue(tag, "src");
}

function extractAttributeValue(tag: string, attribute: string): string | null {
  const lower = tag.toLowerCase();
  const needle = `${attribute}=`;
  const index = lower.indexOf(needle);
  if (index === -1) return null;

  const valueStart = index + needle.length;
  const quote = tag[valueStart];

  if (quote === '"' || quote === "'") {
    const closing = tag.indexOf(quote, valueStart + 1);
    if (closing === -1) return null;
    return tag.slice(valueStart + 1, closing).trim();
  }

  // Unquoted attribute
  const rest = tag.slice(valueStart);
  const end = rest.search(/\s|>/);
  const value = end === -1 ? rest : rest.slice(0, end);
  return value.trim().replace(/>$/, "") || null;
}

function stripTags(value: string): string {
  let output = "";
  let insideTag = false;
  for (const ch of value) {
    if (ch === "<") {
      insideTag = true;
    } else if (ch === ">") {
      insideTag = false;
    } else if (!insideTag) {
      output += ch;
    }
  }
  return output;
}
