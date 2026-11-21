<?php

use App\Mcp\Support\ArtifactGuidance;
use App\Support\AiToolSetup;

/*
 * RUB-550: an agent only follows what it is told. These pin the text that tells
 * it to use Artfct and not the built-in artifact, to follow the person's save
 * setting for anything unasked, and where to write the project block, in every
 * place that text appears: the project blocks, the playbooks, the artfct-init
 * assets, the server's publish guidance and instructions, and the skills the
 * Claude app loads.
 *
 * Datasets here are plain names, never content. A data provider runs before the
 * app is booted, so anything that needs the container (the pasted prompt reads
 * config) must be built inside the test, or the whole dataset errors out.
 */

const BLOCK_PLACES = [
    'one-line rule',
    'long block',
    'pasted prompt',
    'claude-code playbook',
    'cursor playbook',
    'grok-build playbook',
    'coding-agent playbook',
    'artfct-init global block',
    'artfct-init guidelines template',
];

function agentSetup(): AiToolSetup
{
    return new AiToolSetup('https://example.test');
}

/** The text an agent reads at one place the project block is shown. */
function blockAt(string $place): string
{
    $playbook = fn (string $host): string => ArtifactGuidance::topic('onboarding', $host)['text'];

    return match ($place) {
        'one-line rule' => agentSetup()->agentInstructionLine(),
        'long block' => agentSetup()->projectInstructions(),
        'pasted prompt' => agentSetup()->agentSetupPrompt(),
        'claude-code playbook' => $playbook('claude-code'),
        'cursor playbook' => $playbook('cursor'),
        'grok-build playbook' => $playbook('grok-build'),
        'coding-agent playbook' => $playbook('coding-agent'),
        'artfct-init global block' => (string) file_get_contents(base_path('skills/artfct-init/assets/entrypoint-block-global.md')),
        'artfct-init guidelines template' => (string) file_get_contents(base_path('skills/artfct-init/assets/GUIDELINES.template.md')),
    };
}

test('every place the block appears tells the agent to use Artfct and not a built-in artifact when asked to publish or share', function (string $place) {
    expect(blockAt($place))->toContain('built-in')
        ->toContain('deploy_artifact');
})->with(BLOCK_PLACES);

test('every place the block appears keeps the way out: a built-in artifact asked for by name is fine', function (string $place) {
    expect(blockAt($place))->toContain('unless a built-in artifact is asked for by name');
})->with(BLOCK_PLACES);

test('every place the block appears makes unasked publishing follow the save setting, never an unconditional rule', function (string $place) {
    expect(blockAt($place))->toContain('save_to_artfct')
        ->toContain('get_connection')
        // The wording that told every agent to publish anything it made.
        ->not->toContain('publish anything worth keeping')
        ->not->toContain('Publish anything worth keeping')
        ->not->toContain('Publish anything an AI makes')
        ->not->toContain('When you make something worth keeping');
})->with(BLOCK_PLACES);

test('the long block and the guidelines template spell out what always, ask and never mean', function () {
    expect(blockAt('long block'))
        ->toContain('`always` publishes it and says where')
        ->toContain('`ask` offers to save it once you have finished')
        ->toContain('`never` neither publishes nor offers')
        ->and(blockAt('artfct-init guidelines template'))
        ->toContain('`always` publishes it and says where')
        ->toContain('`ask` offers to save it once the work is finished')
        ->toContain('`never` neither publishes nor offers');
});

test('the server tells an agent to use deploy_artifact and not a built-in artifact feature, in guidance and in instructions', function () {
    expect(ArtifactGuidance::topic('publish')['text'])
        ->toContain('do it with `deploy_artifact` and give them the link, not with a built-in artifact feature, unless they ask for a built-in one by name')
        ->toContain('A built-in artifact you already made can still be saved to Artfct afterwards, but only if the preference below allows it')
        // The earlier wording read as permission even on `never`.
        ->not->toContain('as the preference below allows')
        ->and(ArtifactGuidance::INSTRUCTIONS)
        ->toContain('use deploy_artifact and give the link, not a built-in artifact feature, unless a built-in one is asked for by name')
        // The preference rule is still there, after the which-tool rule.
        ->toContain('save_to_artfct preference from get_connection');
});

test('the skills the Claude app loads carry the same rule', function () {
    $main = file_get_contents(base_path('skills/artfct/SKILL.md'));
    $publish = file_get_contents(base_path('skills/artfct-publish/SKILL.md'));

    expect($main)->toContain('deploy with `deploy_artifact` and give them the link. Do not use a built-in artifact feature for that unless they ask for a built-in one by name')
        ->and($publish)->toContain('not with a built-in artifact feature, unless they ask for a built-in one by name');
});

/*
 * Claude Code reads AGENTS.md only when there is no CLAUDE.md in the working
 * directory or any directory above it, and reads only CLAUDE.md when both exist
 * unless it imports AGENTS.md (Claude Code docs, v2.1.277 and later). Writing to
 * the wrong one means the block is never read, or hides a project's AGENTS.md.
 */
test('the Claude Code playbook picks the project file the way Claude Code reads them', function () {
    $text = blockAt('claude-code playbook');

    expect($text)
        ->toContain('if a `CLAUDE.md` or `.claude/CLAUDE.md` exists in this folder or any folder above it, use it, unless it imports `AGENTS.md` (a line `@AGENTS.md`), in which case use the `AGENTS.md` it imports, so every tool gets the block')
        ->toContain('If only an `AGENTS.md` exists (no `CLAUDE.md` in this folder or any folder above it), use it, and do not create a `CLAUDE.md`, because that would stop Claude Code reading `AGENTS.md`')
        ->toContain('If neither exists, ask the person which to create')
        ->toContain('If both exist and `CLAUDE.md` does not import `AGENTS.md`, use `CLAUDE.md` and offer separately to add the block to `AGENTS.md` for other tools')
        // A CLAUDE.md in a parent folder is someone else's shared file.
        ->toContain('If the file you chose is in a folder above this project (a `CLAUDE.md` or the `AGENTS.md` it imports), say which file it is and ask before you write to it, and never use one in the person\'s home folder')
        // The old wording that let an agent write into a file Claude Code ignores.
        ->not->toContain('(or `AGENTS.md` if the project uses that)');
});

test('the pasted prompt, used when the agent could not read the playbook, never creates a CLAUDE.md that hides an AGENTS.md', function () {
    expect(blockAt('pasted prompt'))
        ->toContain('If the project has an `AGENTS.md` and no `CLAUDE.md` in this folder or any folder above it, use `AGENTS.md`, because creating a `CLAUDE.md` would stop Claude Code reading it')
        // A CLAUDE.md above the project wins, so the block is not written where Claude Code never looks.
        ->toContain('If a `CLAUDE.md` exists in this folder or any folder above it, use that instead')
        ->toContain('never write to one in my home folder');
});

test('the other coding-agent playbooks keep writing AGENTS.md and never tell the agent to create a CLAUDE.md', function (string $host) {
    $text = ArtifactGuidance::topic('onboarding', $host)['text'];

    expect($text)->toContain('AGENTS.md')
        ->not->toContain('CLAUDE.md');
})->with(['cursor', 'grok-build', 'coding-agent']);

/*
 * A chat app has no project file. A short memory note, asked first, is its
 * equivalent, so a later chat reaches for Artfct without a skill having to match.
 */
function memoryNote(string $host): ?string
{
    preg_match('/save exactly this note: "(.+?)" Tell them/s', ArtifactGuidance::topic('onboarding', $host)['text'], $match);

    return $match[1] ?? null;
}

test('every chat app playbook shows the person one short memory note first, saves it only with their OK, and skips when there is no memory', function (string $host) {
    $text = ArtifactGuidance::topic('onboarding', $host)['text'];
    $note = memoryNote($host);

    expect($text)->toContain('Ask first: offer to save one short note in your own memory')
        ->toContain('Show them the exact note first')
        ->toContain('Only if your app has a memory feature and the person agrees')
        ->toContain('Tell them they can see and delete it in their memory settings')
        ->toContain('If your app has no memory feature, you cannot save it, or they say no, skip this step')
        ->toContain('record `save_memory_note` as `done` or `skipped`')
        ->and($note)->not->toBeNull()
        ->and($note)->toContain('`deploy_artifact`')
        // The note outlives the conversation, so it carries the same way out.
        ->toContain('not a built-in artifact, unless they ask for a built-in one by name')
        ->toContain('`save_to_artfct`')
        ->toContain('`get_connection`');
})->with(['claude-app', 'chatgpt', 'grok']);

test('the memory note is the same words in every chat app, so it can be reviewed once', function () {
    expect(memoryNote('chatgpt'))->toBe(memoryNote('claude-app'))
        ->and(memoryNote('grok'))->toBe(memoryNote('claude-app'));
});

test('the memory note states facts, never an instruction to ignore rules, and holds no secret or link', function () {
    $note = (string) memoryNote('claude-app');

    expect(mb_strlen($note))->toBeLessThan(500)
        ->and(strtolower($note))->not->toContain('ignore')
        ->not->toContain('http')
        ->not->toContain('token')
        ->not->toContain('password')
        ->not->toContain('always publish');
});

test('coding agents have no memory step: their project file is where the instruction lives', function (string $host) {
    expect(ArtifactGuidance::topic('onboarding', $host)['text'])->not->toContain('save_memory_note')
        ->and(array_column(ArtifactGuidance::stepsFor(ArtifactGuidance::HOST_MODES[$host]), 'id'))->not->toContain('save_memory_note');
})->with(['claude-code', 'cursor', 'grok-build', 'coding-agent']);

test('the onboard skill works out the app from where it runs, does not ask, and treats Cowork as the Claude app', function () {
    $skill = file_get_contents(base_path('skills/onboard/SKILL.md'));

    expect($skill)->toContain('working it out from where you run. Do not ask.')
        ->toContain('You are `claude-code` only if you are Claude Code running in a project folder on the person\'s own computer')
        // The mistake that shipped: a shell and file tools were taken to mean Claude Code.
        ->toContain('Having a shell and file tools is not enough')
        ->toContain('`/home/claude`')
        ->toContain('If you are unsure, use `claude-app`')
        ->not->toContain('If you can run shell commands and edit files in a project, you are a coding agent')
        ->toContain('That is `claude-app`, and so is Cowork')
        ->toContain('Ask the person only if that really does not settle it')
        ->and($skill)->toContain('`save_memory_note`')
        ->toContain('write exactly the note the server gives you');
});

/*
 * A Claude app run with code execution has a shell, file tools and a folder
 * (/home/claude). It was taken for Claude Code and wrote a CLAUDE.md into a
 * sandbox that disappears, and the memory note was never offered. The playbook
 * an agent receives after a wrong guess must send it back to the right one.
 */
test('the Claude Code playbook sends an agent in a scratch folder back to the Claude app playbook before it writes anything', function () {
    $text = blockAt('claude-code playbook');

    expect($text)->toContain('your working folder must be a project on the person\'s own computer')
        ->toContain('`/home/claude`')
        ->toContain('you are the Claude app with a code sandbox, not Claude Code')
        ->toContain('write no file, call `get_guidance` again with `host` set to `claude-app`')
        ->toContain('A real project that happens to sit under a folder with that name is fine')
        // The check comes before the steps.
        ->and(strpos($text, 'First check that is true'))->not->toBeFalse()
        ->and(strpos($text, '1. Check the connection'))->not->toBeFalse()
        ->and(strpos($text, 'First check that is true'))->toBeLessThan(strpos($text, '1. Check the connection'));
});

test('the other generic playbooks catch the same mistake from their side', function () {
    $coding = blockAt('coding-agent playbook');
    $app = ArtifactGuidance::topic('onboarding', 'claude-app')['text'];
    $unknown = ArtifactGuidance::topic('onboarding', 'unknown')['text'];

    expect($coding)->toContain('you are a chat app with a code sandbox, not a coding agent')
        ->toContain('`/home/claude`')
        ->toContain('call `get_guidance` again with `host` set to your chat app (`claude-app`, `chatgpt` or `grok`)')
        ->and(strpos($coding, 'First check that is true'))->toBeLessThan(strpos($coding, '1. Check the connection'))
        // The way back: a real coding agent labelled claude-app must be able to leave.
        ->and($app)->toContain('If you are in fact Claude Code or another coding agent working in a project folder on the person\'s own computer, stop, record nothing for this host, and call `get_guidance` again with `host` set to `claude-code` or `coding-agent`')
        ->and($unknown)->toContain('A chat app that can run code in a sandbox')
        ->toContain('is still a chat app, not a coding agent');
});

test('the guidance version moved with the text', function () {
    expect(ArtifactGuidance::VERSION)->toBe('1.9.0');
});
