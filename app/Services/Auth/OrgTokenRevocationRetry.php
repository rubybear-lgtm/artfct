<?php

namespace App\Services\Auth;

use App\Models\OrgToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrgTokenRevocationRetry
{
    /** @param iterable<OrgToken> $tokens */
    public function persist(iterable $tokens): void
    {
        foreach ($tokens as $token) {
            DB::table('org_token_revocation_retries')->insertOrIgnore([
                'jti_hash' => hash('sha256', $token->jti),
                'encrypted_jti' => Crypt::encryptString($token->jti),
                'expires_at' => $token->expires_at,
                'attempts' => 0,
                'retry_after' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function processDue(int $limit = 100): int
    {
        DB::table('org_token_revocation_retries')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now()->subDays(30))
            ->delete();

        DB::table('org_token_revocation_retries')
            ->whereNull('completed_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $pending = DB::table('org_token_revocation_retries')
            ->whereNull('completed_at')
            ->where('expires_at', '>', now())
            ->where('retry_after', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $retry) {
            $attemptedAt = now();

            try {
                $jti = Crypt::decryptString($retry->encrypted_jti);
                $revoked = RevocationWriter::default()->revoke($jti, Carbon::parse($retry->expires_at));
            } catch (\Throwable $exception) {
                $revoked = false;

                Log::warning('Worker token revocation retry failed.', [
                    'retry_id' => $retry->id,
                    'exception' => $exception::class,
                ]);
            }

            if ($revoked) {
                DB::table('org_token_revocation_retries')
                    ->where('id', $retry->id)
                    ->whereNull('completed_at')
                    ->update([
                        'completed_at' => $attemptedAt,
                        'last_attempt_at' => $attemptedAt,
                        'updated_at' => $attemptedAt,
                    ]);

                continue;
            }

            $attempts = $retry->attempts + 1;
            $delaySeconds = min(3600, 60 * (2 ** min($attempts - 1, 6)));

            DB::table('org_token_revocation_retries')
                ->where('id', $retry->id)
                ->whereNull('completed_at')
                ->update([
                    'attempts' => $attempts,
                    'last_attempt_at' => $attemptedAt,
                    'retry_after' => $attemptedAt->copy()->addSeconds($delaySeconds),
                    'updated_at' => $attemptedAt,
                ]);
        }

        return $pending->count();
    }
}
