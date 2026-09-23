const DEFAULT_THUMBNAIL = 'https://artfct.dev/og-image.svg';
const AES_IV_BYTES = 12;
const SHARE_CODE_LENGTH = 10;

/// Version tag carried in the share fragment. A fragment without it predates
/// the salted KDF and is opened with the legacy SHA-256 derivation.
const KDF_VERSION = 2;
const KDF_SALT_BYTES = 16;

/// Must match `KDF_ITERATIONS` in `mcp-server/src/artifact_crypto.rs` and in the
/// viewer the Worker serves. All three derive the same key or nothing opens;
/// `tests/Feature/ArtifactKeyDerivationTest.php` pins the shared vector.
const KDF_ITERATIONS = 210_000;
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
    const saltBytes = new Uint8Array(KDF_SALT_BYTES);

    crypto.getRandomValues(ivBytes);
    crypto.getRandomValues(saltBytes);

    const cryptoKey = await deriveAesKey(shareCode, saltBytes);
    const ciphertext = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: ivBytes },
        cryptoKey,
        textEncoder.encode(html),
    );

    return {
        bodyCiphertextB64: toBase64Url(new Uint8Array(ciphertext)),
        bodyIvB64: toBase64Url(ivBytes),
        keyFragment: `#p=${shareCode}&s=${toBase64Url(saltBytes)}&v=${KDF_VERSION}`,
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

/// Derives the raw key bytes with PBKDF2-HMAC-SHA256 over a per-artifact salt.
///
/// The previous derivation was a single unsalted SHA-256 of the share code, so
/// the same code produced the same key for every artifact and a guess cost one
/// hash. Exported so the shared test vector can be asserted against it.
export async function deriveAesKeyBytes(
    shareCode: string,
    salt: Uint8Array<ArrayBuffer>,
): Promise<ArrayBuffer> {
    const keyMaterial = await crypto.subtle.importKey(
        'raw',
        textEncoder.encode(shareCode),
        'PBKDF2',
        false,
        ['deriveBits'],
    );

    return crypto.subtle.deriveBits(
        {
            name: 'PBKDF2',
            salt,
            iterations: KDF_ITERATIONS,
            hash: 'SHA-256',
        },
        keyMaterial,
        256,
    );
}

async function deriveAesKey(
    shareCode: string,
    salt: Uint8Array<ArrayBuffer>,
): Promise<CryptoKey> {
    const bits = await deriveAesKeyBytes(shareCode, salt);

    return crypto.subtle.importKey('raw', bits, { name: 'AES-GCM' }, false, [
        'encrypt',
    ]);
}

function randomShareCode(length: number): string {
    const random = crypto.getRandomValues(new Uint8Array(length));

    return Array.from(random, (byte) => BASE62[byte % BASE62.length]).join('');
}
