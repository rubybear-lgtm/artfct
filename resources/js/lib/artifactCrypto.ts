const DEFAULT_THUMBNAIL = 'https://artfct.dev/og-image.svg';
const AES_IV_BYTES = 12;
const SHARE_CODE_LENGTH = 10;
const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

const textEncoder = new TextEncoder();

export interface ArtifactMetadata {
    title: string;
    description: string;
    thumbnail: string;
}

export interface ArtifactEncryptionResult {
    bodyCiphertextB64: string;
    bodyIvB64: string;
    keyFragment: string;
}

export async function encryptArtifactBody(
    html: string,
): Promise<ArtifactEncryptionResult> {
    const shareCode = randomShareCode(SHARE_CODE_LENGTH);
    const ivBytes = new Uint8Array(AES_IV_BYTES);

    crypto.getRandomValues(ivBytes);

    const cryptoKey = await deriveAesKey(shareCode);
    const ciphertext = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: ivBytes },
        cryptoKey,
        textEncoder.encode(html),
    );

    return {
        bodyCiphertextB64: toBase64Url(new Uint8Array(ciphertext)),
        bodyIvB64: toBase64Url(ivBytes),
        keyFragment: `#${shareCode}`,
    };
}

export function extractArtifactMetadata(
    html: string,
    fallbackTitle: string,
): ArtifactMetadata {
    const document = new DOMParser().parseFromString(html, 'text/html');
    const title =
        normalizeText(
            document
                .querySelector('meta[property="og:title"]')
                ?.getAttribute('content'),
        ) ??
        normalizeText(document.title) ??
        normalizeText(textContent(document.querySelector('h1'))) ??
        fallbackTitle;

    const description =
        normalizeText(
            document
                .querySelector('meta[name="description"]')
                ?.getAttribute('content'),
        ) ??
        normalizeText(
            document
                .querySelector('meta[property="og:description"]')
                ?.getAttribute('content'),
        ) ??
        normalizeText(textContent(document.querySelector('p'))) ??
        title;

    const thumbnail =
        normalizeText(
            document
                .querySelector('meta[property="og:image"]')
                ?.getAttribute('content'),
        ) ??
        normalizeText(document.querySelector('img')?.getAttribute('src')) ??
        DEFAULT_THUMBNAIL;

    return {
        title,
        description,
        thumbnail,
    };
}

export function withArtifactFragment(url: string, fragment: string): string {
    return `${url}${fragment}`;
}

function normalizeText(value: string | null | undefined): string | null {
    const trimmed = value?.replace(/\s+/g, ' ').trim();

    return trimmed ? trimmed : null;
}

function textContent(element: Element | null): string | null {
    return normalizeText(element?.textContent ?? null);
}

function toBase64Url(bytes: Uint8Array): string {
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/u, '');
}

async function deriveAesKey(shareCode: string): Promise<CryptoKey> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        textEncoder.encode(shareCode),
    );

    return crypto.subtle.importKey('raw', digest, { name: 'AES-GCM' }, false, [
        'encrypt',
    ]);
}

function randomShareCode(length: number): string {
    const random = crypto.getRandomValues(new Uint8Array(length));

    return Array.from(random, (byte) => BASE62[byte % BASE62.length]).join('');
}
