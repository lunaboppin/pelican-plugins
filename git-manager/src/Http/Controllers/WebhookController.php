<?php

namespace GitManager\Http\Controllers;

use GitManager\Models\GitWebhook;
use GitManager\Services\GitGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives GitHub/GitLab push events and pulls the configured repository.
 * GitHub requests are verified with X-Hub-Signature-256, GitLab with X-Gitlab-Token.
 */
class WebhookController
{
    public function __invoke(Request $request, string $uuid): JsonResponse
    {
        $webhook = GitWebhook::query()->where('uuid', $uuid)->first();
        if (!$webhook) {
            return response()->json(['error' => 'not found'], 404);
        }

        if (!$this->verified($request, $webhook->secret)) {
            return response()->json(['error' => 'invalid signature'], 403);
        }

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['ok' => true, 'message' => 'pong']);
        }

        $ref = (string) $request->json('ref', '');
        if ($webhook->branch && $ref !== 'refs/heads/' . $webhook->branch) {
            return response()->json(['ok' => true, 'message' => 'ignored: push was not to ' . $webhook->branch], 202);
        }

        $webhook->forceFill(['last_triggered_at' => now(), 'last_status' => 'running'])->save();

        defer(function () use ($webhook) {
            try {
                $result = GitGateway::call($webhook->server, 'pull', ['path' => $webhook->path], 'webhook');
                $webhook->forceFill([
                    'last_status' => $result['ok'] ? 'success' : 'failed',
                    'last_output' => Str::limit((string) ($result['output'] ?? ''), 4000),
                ])->save();
            } catch (Throwable $exception) {
                $webhook->forceFill(['last_status' => 'failed', 'last_output' => $exception->getMessage()])->save();
            }
        });

        return response()->json(['ok' => true, 'message' => 'pull queued'], 202);
    }

    private function verified(Request $request, string $secret): bool
    {
        $github = (string) $request->header('X-Hub-Signature-256', '');
        if ($github !== '') {
            return hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $github);
        }

        $gitlab = (string) $request->header('X-Gitlab-Token', '');
        if ($gitlab !== '') {
            return hash_equals($secret, $gitlab);
        }

        return false;
    }
}
