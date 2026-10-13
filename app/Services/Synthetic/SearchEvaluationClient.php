<?php

namespace App\Services\Synthetic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Authenticated Streamable HTTP client for the hosted staging MCP. Never replays writes. */
class SearchEvaluationClient
{
    private ?string $sessionId = null;

    private string $protocolVersion = '2025-11-25';

    private int $requestId = 0;

    public function __construct(private readonly string $endpoint, private readonly string $token) {}

    public function initialize(): void
    {
        if ($this->endpoint !== 'https://staging.artfct.dev/mcp' || trim($this->token) === '') {
            throw new RuntimeException('An authenticated staging MCP endpoint is required.');
        }
        $result = $this->rpc('initialize', ['protocolVersion' => $this->protocolVersion, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'artfct-search-evaluation', 'version' => '1.0.0']]);
        if (! in_array($result['protocolVersion'] ?? null, ['2025-11-25', '2025-06-18', '2025-03-26'], true) || $this->sessionId === null) {
            throw new RuntimeException('MCP initialization did not establish a supported session.');
        }
        $this->protocolVersion = $result['protocolVersion'];
        $this->rpc('notifications/initialized', [], notification: true);
    }

    /**
     * @param  list<string>  $requiredScopes
     * @return array<string, mixed>
     */
    public function verifyWorkspace(string $slug, array $requiredScopes): array
    {
        SyntheticSeeder::assertSafe($slug);
        $connection = $this->call('get_connection');
        if (($connection['authenticated'] ?? false) !== true || ($connection['organization'] ?? null) !== $slug || array_diff($requiredScopes, $connection['scopes'] ?? []) !== []) {
            throw new RuntimeException('MCP workspace identity or required scopes do not match the synthetic run.');
        }

        return ['organization' => $slug, 'scopes' => $connection['scopes'], 'protocol_version' => $this->protocolVersion];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments = []): array
    {
        if ($this->sessionId === null) {
            throw new RuntimeException('Initialize the MCP client before calling tools.');
        }
        $result = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? (object) [] : $arguments]);
        if (($result['isError'] ?? false) === true) {
            $code = $result['_meta']['artfct']['errorCode'] ?? 'tool_error';
            throw new RuntimeException('MCP tool failed: '.(preg_match('/^[a-z_]+$/', $code) ? $code : 'tool_error'));
        }
        if (! is_array($result['structuredContent'] ?? null)) {
            throw new RuntimeException('MCP tool did not return structured content.');
        }

        return $result['structuredContent'];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params, bool $notification = false): array
    {
        $id = ++$this->requestId;
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Client-Name' => 'artfct-search-evaluation'];
        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params === [] ? (object) [] : $params];
        if (! $notification) {
            $body['id'] = $id;
        }
        try {
            $response = Http::withToken($this->token)->withHeaders($headers)->withoutRedirecting()->connectTimeout(10)->timeout(120)->post($this->endpoint, $body);
        } catch (ConnectionException) {
            throw new RuntimeException('MCP transport connection failed.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('MCP transport HTTP '.$response->status().'.');
        }
        if ($notification) {
            return [];
        }
        $payload = $response->json();
        if (str_starts_with((string) $response->header('Content-Type'), 'text/event-stream')) {
            $payload = null;
            foreach (preg_split('/\r?\n\r?\n/', $response->body()) as $event) {
                $lines = [];
                foreach (preg_split('/\r?\n/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $lines[] = ltrim(substr($line, 5));
                    }
                }
                $candidate = json_decode(implode("\n", $lines), true);
                if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
                    $payload = $candidate;
                    break;
                }
            }
        }
        if (! is_array($payload) || ($payload['jsonrpc'] ?? null) !== '2.0' || ($payload['id'] ?? null) !== $id || isset($payload['error']) || ! is_array($payload['result'] ?? null)) {
            throw new RuntimeException('MCP protocol response failed validation.');
        }
        if ($method === 'initialize') {
            $this->sessionId = $response->header('MCP-Session-Id') ?: null;
        }

        return $payload['result'];
    }
}
