<?php

namespace App\Http\Middleware;

use Closure;
use Error;
use ErrorException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TypeError;

/**
 * Fail closed on JSON-RPC envelopes that laravel/mcp v0.7 TypeErrors on
 * (boolean/object ids, non-array params, non-string tool names/arguments)
 * before they become HTTP 500s. Returns a bounded JSON-RPC error instead.
 */
final class ValidateMcpJsonRpcEnvelope
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $raw = $request->getContent();

        if ($raw === '') {
            return $this->error(null, -32700, 'Parse error: Invalid JSON was received by the server.');
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error(null, -32700, 'Parse error: Invalid JSON was received by the server.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->error(null, -32600, 'Invalid Request: JSON-RPC body must be an object.');
        }

        $hasId = array_key_exists('id', $decoded);
        $safeId = $this->safeId($decoded['id'] ?? null);

        if ($hasId && $safeId === false) {
            return $this->error(null, -32600, 'Invalid Request: The [id] member must be a string, number, or null.');
        }

        $id = $hasId ? $safeId : null;

        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, -32600, 'Invalid Request: The [jsonrpc] member must be exactly [2.0].');
        }

        if (! isset($decoded['method']) || ! is_string($decoded['method']) || $decoded['method'] === '') {
            return $this->error($id, -32600, 'Invalid Request: The [method] member is required and must be a string.');
        }

        if (array_key_exists('params', $decoded) && ! is_array($decoded['params'])) {
            return $this->error($id, -32602, 'Invalid params: The [params] member must be an object or array when present.');
        }

        $params = $decoded['params'] ?? [];

        if ($decoded['method'] === 'tools/call') {
            if (array_key_exists('name', $params) && ! is_string($params['name'])) {
                return $this->error($id, -32602, 'Invalid params: tools/call [name] must be a string.');
            }

            if (array_key_exists('arguments', $params) && ! is_array($params['arguments'])) {
                return $this->error($id, -32602, 'Invalid params: tools/call [arguments] must be an object.');
            }
        }

        try {
            $response = $next($request);
        } catch (TypeError|Error|ErrorException $exception) {
            report($exception);

            return $this->error($id, -32603, 'Internal error: the request could not be processed.');
        }

        // When APP_DEBUG is on, laravel/mcp rethrows TypeErrors and the HTTP
        // kernel turns them into 500 JSON exception pages. Rewrite those as
        // bounded JSON-RPC errors so clients never see a bare 500 for framing.
        if ($response->getStatusCode() >= 500) {
            return $this->error($id, -32603, 'Internal error: the request could not be processed.');
        }

        return $response;
    }

    /**
     * @return string|int|null|false false when the id is not JSON-RPC-safe
     */
    private function safeId(mixed $id): string|int|null|false
    {
        if ($id === null || is_string($id) || is_int($id)) {
            return $id;
        }

        return false;
    }

    private function error(string|int|null $id, int $code, string $message): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 200);
    }
}
