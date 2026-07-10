/**
 * artfct Pi Extension
 *
 * Registers the `deploy_to_canvas` tool natively in Pi, so agents can deploy
 * self-contained HTML artifacts directly — no external MCP server needed.
 *
 * Also registers:
 * - `/artfct` command to deploy the last generated HTML from the session
 * - `session_start` hook to notify that artfct tools are available
 *
 * Tool capabilities:
 * - Encrypts HTML client-side with AES-256-GCM before upload
 * - Extracts title, description, and thumbnail metadata from HTML
 * - Supports public, secure, and ephemeral tiers with configurable TTL
 */

import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import { Type } from "typebox";
import { StringEnum } from "@earendil-works/pi-ai";

import { randomShareCode, encrypt } from "./crypto";
import { extractTitle, extractDescription, extractThumbnail } from "./metadata";
import { deployArtifact } from "./api";

const MAX_HTML_BYTES = 1_048_576; // 1 MiB

// ── Tool schema ────────────────────────────────────────────────────────────────

const DeployParams = Type.Object({
  html: Type.String({ description: "Complete, valid, self-contained HTML payload to deploy." }),
  tier: StringEnum(["public", "secure", "ephemeral"] as const, {
    description: "Access control tier: public (anyone with URL), secure (authenticated only), ephemeral (auto-expires).",
  }),
  ttl_minutes: Type.Optional(
    Type.Integer({
      minimum: 1,
      description: "Auto-delete after N minutes. Only meaningful when tier is 'ephemeral'.",
    }),
  ),
});

// ── Extension ──────────────────────────────────────────────────────────────────

export default function artfctExtension(pi: ExtensionAPI) {
  // ── deploy_to_canvas tool ───────────────────────────────────────────────

  pi.registerTool({
    name: "deploy_to_canvas",
    label: "Deploy to Canvas",
    description:
      "Deploy a self-contained HTML page to artfct.dev and return a shareable URL. " +
      "Call this whenever you generate visual HTML output — dashboards, charts, reports, " +
      "demos, presentations, or any interactive page the user needs to view or share. " +
      "Do not emit raw HTML code blocks when this tool is available.",
    promptSnippet: "Deploy self-contained HTML to artfct.dev and return a shareable URL",
    promptGuidelines: [
      "Use deploy_to_canvas whenever you generate self-contained HTML that the user needs to view or share — dashboards, charts, reports, presentations, or interactive demos.",
      "Always include a complete <!DOCTYPE html> document with all CSS and JS inlined or loaded from public CDNs.",
      "Default to tier 'public' for shareable output; use 'ephemeral' with ttl_minutes for quick throwaway previews.",
      "Present the returned URL clearly to the user after a successful deploy.",
    ],
    parameters: DeployParams,

    async execute(_toolCallId, params, signal, _onUpdate, _ctx) {
      const html = params.html.trim();

      if (!html) {
        return {
          content: [{ type: "text", text: "Error: html payload is empty." }],
          details: { error: "empty_payload" },
          isError: true,
        };
      }

      if (html.length > MAX_HTML_BYTES) {
        return {
          content: [
            {
              type: "text",
              text: `Error: HTML payload (${(html.length / 1024).toFixed(0)} KiB) exceeds the ${MAX_HTML_BYTES / 1024} KiB limit. Inline large assets as external CDN URLs instead of base64.`,
            },
          ],
          details: { error: "size_limit_exceeded", size: html.length },
          isError: true,
        };
      }

      // Extract metadata
      const title = extractTitle(html);
      const description = extractDescription(html);
      const thumbnail = extractThumbnail(html);

      // Encrypt
      const shareCode = randomShareCode();
      const { ciphertextB64, ivB64 } = encrypt(html, shareCode);

      // Deploy
      let result;
      try {
        result = await deployArtifact(
          {
            body_ciphertext_b64: ciphertextB64,
            body_iv_b64: ivB64,
            tier: params.tier,
            ttl_minutes: params.ttl_minutes,
            title,
            description,
            thumbnail,
            preview_blurred: true,
          },
          signal,
        );
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        return {
          content: [
            {
              type: "text",
              text: `Failed to deploy artifact: ${message}`,
            },
          ],
          details: { error: "api_error", message },
          isError: true,
        };
      }

      const fullUrl = `${result.url}#${shareCode}`;
      const expiresInfo =
        result.tier === "ephemeral"
          ? `\nExpires: ${result.expires_at}`
          : "\nNo expiry.";

      const tierLabel =
        result.tier === "public"
          ? "Public link"
          : result.tier === "secure"
            ? "Secure link (authenticated)"
            : "Ephemeral link";

      return {
        content: [
          {
            type: "text",
            text: [
              `Deployed → ${fullUrl}`,
              "",
              `${tierLabel}${expiresInfo}`,
            ].join("\n"),
          },
        ],
        details: {
          id: result.id,
          url: fullUrl,
          canonical_url: result.url,
          tier: result.tier,
          expires_at: result.expires_at,
          title: result.title,
          description: result.description,
          thumbnail: result.thumbnail,
        },
      };
    },
  });

  // ── /artfct command ──────────────────────────────────────────────────────

  pi.registerCommand("artfct", {
    description: "Show artfct extension status and deploy info",
    handler: async (_args, ctx) => {
      if (ctx.hasUI) {
        ctx.ui.notify(
          "artfct: deploy_to_canvas tool is registered. Tell me what HTML to deploy!",
          "info",
        );
      }
    },
  });

  // ── session_start ────────────────────────────────────────────────────────

  pi.on("session_start", async (_event, ctx) => {
    if (ctx.hasUI) {
      ctx.ui.notify("artfct extension loaded — deploy_to_canvas ready", "info");
    }
  });
}
