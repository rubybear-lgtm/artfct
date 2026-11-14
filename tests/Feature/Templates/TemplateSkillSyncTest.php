<?php

use App\Mcp\Support\ArtifactGuidance;
use App\Services\Templates\TemplateSkillSync;

/*
 * Spec 21 mechanism, deferred step 5: the skill-sync mechanism copies a
 * template's skill source to the served guidance and Agent Skills files.
 * Only the fixture template is exercised — the kanban/wiki/announcements
 * specs reuse the mechanism for their own topics.
 */

function syncHarness(): array
{
    $root = sys_get_temp_dir().'/skill-sync-'.uniqid();
    mkdir($root, 0755, true);

    $templates = $root.'/templates';
    mkdir($templates, 0755, true);
    File::copyDirectory(base_path('tests/Fixtures/templates'), $templates);

    return [$root, new TemplateSkillSync($templates, $root.'/guidance', $root.'/skills')];
}

test('sync_writes_the_guidance_body_and_skill_file_for_the_fixture_template', function () {
    [$root, $sync] = syncHarness();

    $result = $sync->sync('fixture');

    $guidance = "# Fixture skill\n\nAdd a card with `db_set`, move it with `db_update`, list with `db_list`.\n";

    expect($result['topic'])->toBe('fixture')
        ->and($result['version'])->toBe(ArtifactGuidance::VERSION)
        ->and(file_get_contents($result['guidance_path']))->toBe($guidance)
        ->and($result['guidance_path'])->toBe($root.'/guidance/fixture.md');

    $skill = file_get_contents($result['skill_path']);

    // The McpGuidanceTest contract the generated pair must satisfy once the
    // topic is registered: name frontmatter prefix, guidance verbatim suffix.
    expect($result['skill_path'])->toBe($root.'/skills/artfct-fixture/SKILL.md')
        ->and($skill)->toStartWith("---\nname: artfct-fixture\n")
        ->and(str_ends_with($skill, $guidance))->toBeTrue()
        ->and($skill)->toBe("---\nname: artfct-fixture\ndescription: A minimal template that exercises the catalog, used only by tests.\n---\n\n".$guidance);

    // Re-running is byte-identical.
    $sync->sync('fixture');

    expect(file_get_contents($result['guidance_path']))->toBe($guidance)
        ->and(file_get_contents($result['skill_path']))->toBe($skill);
});

test('sync_refuses_a_template_without_a_skill_source', function () {
    [$root, $sync] = syncHarness();

    unlink($root.'/templates/fixture/skill/SKILL.md');

    expect(fn () => $sync->sync('fixture'))->toThrow(RuntimeException::class, 'no skill source');
});

test('the_sync_command_reports_the_topic_registration_next_step', function () {
    [$root] = syncHarness();

    test()->artisan('templates:sync-skills', [
        'key' => 'fixture',
        '--templates-dir' => $root.'/templates',
        '--guidance-dir' => $root.'/guidance',
        '--skills-dir' => $root.'/skills',
    ])
        ->expectsOutputToContain('Synced [fixture]')
        ->expectsOutputToContain('ArtifactGuidance::TOPICS')
        ->assertSuccessful();

    expect(file_get_contents($root.'/guidance/fixture.md'))->toContain('# Fixture skill')
        ->and(file_get_contents($root.'/skills/artfct-fixture/SKILL.md'))->toStartWith("---\nname: artfct-fixture\n");

    // The fixture stays a test-only topic: syncing never touches production.
    expect(ArtifactGuidance::TOPICS)->not->toContain('fixture');
});
