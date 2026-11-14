<?php

namespace App\Services\Templates;

use App\Mcp\Support\ArtifactGuidance;
use RuntimeException;

/**
 * Skill-sync mechanism (spec 21, deferred step 5; shared by specs 22-24).
 *
 * The skill text lives once, in `resources/templates/<key>/skill/SKILL.md`.
 * Syncing copies its body to the two served locations the
 * `McpGuidanceTest` consistency suite pins down:
 *
 * - `resources/guidance/<topic>.md` — the body verbatim;
 * - `skills/artfct-<topic>/SKILL.md` — `---`, `name:`, `description:` (from
 *   the template.json description) frontmatter plus the body verbatim (the
 *   suite asserts the file starts with the name line and ends with the served guidance text).
 *
 * The topic equals the template key. Registering it in
 * `ArtifactGuidance::TOPICS` (and bumping `VERSION`) stays a deliberate
 * manual step per template spec — the command warns when the topic is
 * missing instead of editing the class.
 */
final class TemplateSkillSync
{
    public function __construct(
        private readonly string $templatesBase,
        private readonly string $guidanceBase,
        private readonly string $skillsBase,
    ) {}

    public static function defaults(): self
    {
        return new self(
            resource_path('templates'),
            resource_path('guidance'),
            base_path('skills'),
        );
    }

    /**
     * @return array{topic: string, guidance_path: string, skill_path: string, version: string}
     */
    public function sync(string $key): array
    {
        $source = $this->templatesBase.'/'.$key.'/skill/SKILL.md';

        if (! is_file($source)) {
            throw new RuntimeException("Template [{$key}] has no skill source at {$source}.");
        }

        $body = self::body((string) file_get_contents($source));

        if (! is_dir($this->guidanceBase)) {
            mkdir($this->guidanceBase, 0755, true);
        }

        $guidancePath = $this->guidanceBase.'/'.$key.'.md';
        file_put_contents($guidancePath, $body);

        $skillDir = $this->skillsBase.'/artfct-'.$key;
        if (! is_dir($skillDir)) {
            mkdir($skillDir, 0755, true);
        }

        $manifest = json_decode((string) @file_get_contents($this->templatesBase.'/'.$key.'/template.json'), true);
        $description = is_array($manifest) && isset($manifest['description']) && is_string($manifest['description']) && trim($manifest['description']) !== ''
            ? $manifest['description']
            : "Agent skill for the {$key} artifact template.";

        $skillPath = $skillDir.'/SKILL.md';
        file_put_contents($skillPath, self::skillFile($key, $body, $description));

        return [
            'topic' => $key,
            'guidance_path' => $guidancePath,
            'skill_path' => $skillPath,
            'version' => ArtifactGuidance::VERSION,
        ];
    }

    public static function registered(string $topic): bool
    {
        return in_array($topic, ArtifactGuidance::TOPICS, true);
    }

    /**
     * The served body: the source without its leading `---` frontmatter
     * block, leading blank lines removed, exactly one trailing newline.
     */
    public static function body(string $source): string
    {
        $body = preg_replace('/\A---[ \t]*\r?\n.*?\r?\n---[ \t]*(?:\r?\n|\z)/s', '', $source, 1) ?? $source;
        $body = ltrim($body, "\r\n");

        return rtrim($body, "\r\n")."\n";
    }

    public static function skillFile(string $topic, string $guidanceBody, string $description): string
    {
        $oneLine = trim(preg_replace('/\s+/', ' ', $description) ?? '');

        return "---\nname: artfct-{$topic}\ndescription: {$oneLine}\n---\n\n".$guidanceBody;
    }
}
