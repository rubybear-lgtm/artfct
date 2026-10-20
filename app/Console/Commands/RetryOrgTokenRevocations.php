<?php

namespace App\Console\Commands;

use App\Services\Auth\MissingRevocationWriterConfiguration;
use App\Services\Auth\OrgTokenRevocationRetry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:retry-org-token-revocations')]
#[Description('Retry Worker denylist writes for revoked org tokens')]
class RetryOrgTokenRevocations extends Command
{
    public function handle(OrgTokenRevocationRetry $retry): int
    {
        try {
            $processed = $retry->processDue();
        } catch (MissingRevocationWriterConfiguration $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Processed {$processed} pending org-token revocation(s).");

        return self::SUCCESS;
    }
}
