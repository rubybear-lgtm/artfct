/**
 * API client for the artfct artifact engine.
 */

const DEFAULT_API_BASE_URL = "https://artfct.dev";

export interface CreateArtifactRequest {
  body_ciphertext_b64: string;
  body_iv_b64: string;
  tier: string;
  ttl_minutes?: number;
  title: string;
  description: string;
  thumbnail: string;
  preview_blurred: boolean;
}

export interface CreateArtifactResponse {
  id: string;
  url: string;
  tier: string;
  expires_at: string;
  title: string;
  description: string;
  thumbnail: string;
  preview_blurred: boolean;
}

function artifactEndpoint(apiBaseUrl: string): string {
  return `${apiBaseUrl.replace(/\/+$/, "")}/v1/artifacts`;
}

export async function deployArtifact(
  request: CreateArtifactRequest,
  signal?: AbortSignal,
): Promise<CreateArtifactResponse> {
  const apiBaseUrl = process.env.ARTFCT_API_BASE_URL || DEFAULT_API_BASE_URL;
  const url = artifactEndpoint(apiBaseUrl);

  const response = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(request),
    signal,
  });

  const body = await response.text();

  if (!response.ok) {
    throw new Error(`Artifact Engine returned ${response.status}: ${body}`);
  }

  return JSON.parse(body) as CreateArtifactResponse;
}
