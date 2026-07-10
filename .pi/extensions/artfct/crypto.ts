/**
 * Encryption utilities for artfct artifacts.
 *
 * Mirrors the logic in mcp-server/src/artifact_crypto.rs:
 * - Random 10-char base62 share code
 * - AES-256 key derived via SHA-256 of share code
 * - Random 12-byte IV
 * - AES-256-GCM encryption
 * - URL-safe base64 (no padding) encoding
 */

import { createHash, randomBytes, createCipheriv } from "node:crypto";

const SHARE_CODE_LENGTH = 10;
const SHARE_CODE_ALPHABET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
const AES_IV_BYTES = 12;
const AES_KEY_BYTES = 32;
const AES_ALGORITHM = "aes-256-gcm";

/** Generate a random share code of given length from the base62 alphabet. */
export function randomShareCode(length: number = SHARE_CODE_LENGTH): string {
  const bytes = randomBytes(length);
  let code = "";
  for (let i = 0; i < length; i++) {
    code += SHARE_CODE_ALPHABET[bytes[i] % SHARE_CODE_ALPHABET.length];
  }
  return code;
}

/** Derive a 32-byte AES key from a share code using SHA-256. */
function deriveKey(shareCode: string): Buffer {
  return createHash("sha256").update(shareCode).digest();
}

/**
 * Encrypt HTML payload with AES-256-GCM.
 * Returns base64url-encoded (no-pad) ciphertext and IV.
 */
export function encrypt(html: string, shareCode: string): { ciphertextB64: string; ivB64: string } {
  const key = deriveKey(shareCode);
  const iv = randomBytes(AES_IV_BYTES);

  const cipher = createCipheriv(AES_ALGORITHM, key, iv);
  const encrypted = Buffer.concat([cipher.update(html, "utf8"), cipher.final()]);
  const authTag = cipher.getAuthTag();

  // Concatenate ciphertext + auth tag (matching Rust ring seal_in_place_append_tag)
  const combined = Buffer.concat([encrypted, authTag]);

  return {
    ciphertextB64: toBase64Url(combined),
    ivB64: toBase64Url(iv),
  };
}

/** Base64-URL encode without padding. */
function toBase64Url(buffer: Buffer): string {
  return buffer
    .toString("base64")
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
}
