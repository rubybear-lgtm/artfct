<?php

namespace App\Console\Commands;

use App\Mcp\Support\ArtifactGuidance;
use App\Services\Templates\TemplateCatalog;
use App\Services\Templates\TemplateSkillSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('templates:sync-skills {key? : Template key to sync; omit to sync every catalog template} {--templates-dir= : Template root (defaults to resources/templates)} {--guidance-dir= : Guidance root (defaults to resources/guidance)} {--skills-dir= : Skills root (defaults to skills/)}')]
#[Description('Copy template skill sources to the served guidance and Agent Skills files')]
class SyncTemplateSkillsCommand extends Command
{
    public function handle(): int
    {
        $templatesDir = $this->option('templates-dir') ?: resource_path('templates');
        $sync = new TemplateSkillSync(
            $templatesDir,
            $this->option('guidance-dir') ?: resource_path('guidance'),
            $this->option('skills-dir') ?: base_path('skills'),
        );

        $key = $this->argument('key');
        $keys = is_string($key) && $key !== ''
            ? [$key]
            : (new TemplateCatalog($templatesDir))->keys();

        if ($keys === []) {
            $this->components->info('No templates to sync.');

            return self::SUCCESS;
        }

        foreach ($keys as $templateKey) {
            $result = $sync->sync($templateKey);

            $this->components->info("Synced [{$templateKey}] → {$result['guidance_path']} + {$result['skill_path']} (guidance version {$result['version']}).");

            if (! TemplateSkillSync::registered($templateKey)) {
                $this->components->warn("Topic [{$templateKey}] is not in ArtifactGuidance::TOPICS yet: register it and bump ArtifactGuidance::VERSION (currently ".ArtifactGuidance::VERSION.').');
            }
        }

        return self::SUCCESS;
    }
}
