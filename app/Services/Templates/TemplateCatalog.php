<?php

namespace App\Services\Templates;

use App\Enums\ArtifactType;

/**
 * Loads and validates the Artfct-authored templates in
 * `resources/templates/<key>/` (spec 21). A malformed template fails here —
 * in CI, not on a pull — so nothing unreviewed reaches a team.
 *
 * Constraints enforced as errors (mirroring what `deploy_artifact` warns
 * about, plus the spec-20 manifest rules): no inline `<script>`, no `on*=`
 * handlers, no `data:` URIs, no external origins, every referenced asset
 * present, total size within the bundle limit, `db` valid under the
 * manifest rules including the 256 KiB seed cap, and the skill present with
 * `skill/SKILL.md` byte-identical to `files/SKILL.md`.
 */
final class TemplateCatalog
{
    public const MAX_BUNDLE_BYTES = 8 * 1024 * 1024;

    public const MAX_SEED_BYTES = 256 * 1024;

    public const MAX_BODY_BYTES = 64 * 1024;

    /** Feature keys a template may name in `requires`. */
    public const KNOWN_FEATURES = ['artifact-db', 'artifact-templates'];

    public function __construct(private readonly string $basePath) {}

    public static function default(): self
    {
        return new self(resource_path('templates'));
    }

    /**
     * Every valid template, sorted by key. Invalid ones are reported and
     * skipped so one broken template cannot hide the gallery.
     *
     * @return list<TemplateDefinition>
     */
    public function all(): array
    {
        $definitions = [];

        foreach ($this->keys() as $key) {
            $errors = $this->validate($key);

            if ($errors !== []) {
                report(new \RuntimeException("Template [{$key}] is invalid: ".implode('; ', $errors)));

                continue;
            }

            $definitions[] = $this->load($key);
        }

        usort($definitions, fn (TemplateDefinition $a, TemplateDefinition $b): int => $a->key <=> $b->key);

        return $definitions;
    }

    public function find(string $key): ?TemplateDefinition
    {
        if (! in_array($key, $this->keys(), true) || $this->validate($key) !== []) {
            return null;
        }

        return $this->load($key);
    }

    /** @return list<string> directory names under the base path */
    public function keys(): array
    {
        if (! is_dir($this->basePath)) {
            return [];
        }

        $keys = [];
        foreach (scandir($this->basePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($this->basePath.'/'.$entry)) {
                continue;
            }

            $keys[] = $entry;
        }

        sort($keys);

        return $keys;
    }

    /**
     * The content errors for one template, empty when it is pullable. Used
     * by the per-template CI test and by `find()`/`all()` to refuse broken
     * templates at runtime.
     *
     * @return list<string>
     */
    public function validate(string $key): array
    {
        $dir = $this->basePath.'/'.$key;

        if (! preg_match('/^[a-z0-9-]+$/', $key)) {
            return ["Template key [{$key}] must be a lowercase slug ([a-z0-9-])."];
        }

        $manifestPath = $dir.'/template.json';
        if (! is_file($manifestPath)) {
            return ["Template [{$key}] is missing template.json."];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return ["Template [{$key}] has a template.json that is not valid JSON."];
        }

        $errors = $this->validateManifest($key, $manifest);
        if ($errors !== []) {
            return $errors;
        }

        $files = $this->readFiles($dir);
        if ($files === null) {
            return ["Template [{$key}] is missing the files/ directory."];
        }

        $errors = array_merge(
            $errors,
            $this->validateFiles($key, $manifest, $files),
            $this->validateDb($key, $manifest['db'] ?? null),
            $this->validateSkill($key, $dir),
        );

        return $errors;
    }

    /**
     * The spec-20 `db` manifest rules, shared with anything else that
     * accepts a manifest (the deploy path when it gains one). Strict: only
     * the shapes spec 20 names are accepted.
     *
     * @return list<string>
     */
    public static function validateDbManifest(mixed $db, string $owner): array
    {
        if (! is_array($db)) {
            return ["{$owner} `db` must be an object with `collections`."];
        }

        $errors = [];
        $unknown = array_diff(array_keys($db), ['collections', 'seed']);
        if ($unknown !== []) {
            $errors[] = "{$owner} `db` has unknown keys [".implode(', ', $unknown).']; only `collections` and `seed` are allowed.';
        }

        $collections = $db['collections'] ?? null;
        if (! is_array($collections) || $collections === []) {
            $errors[] = "{$owner} `db.collections` must be a non-empty object mapping collection names to their spec.";

            return $errors;
        }

        foreach ($collections as $name => $spec) {
            if (! is_string($name) || ! self::isDocName($name, 64)) {
                $errors[] = "{$owner} collection name [{$name}] must be 1-64 characters of [A-Za-z0-9._-].";

                continue;
            }

            if (! is_array($spec) || ! isset($spec['index']) || ! is_string($spec['index']) || ! self::isDocName($spec['index'], 64)) {
                $errors[] = "{$owner} collection [{$name}] must declare an `index` field of 1-64 characters of [A-Za-z0-9._-].";
            }
        }

        $seed = $db['seed'] ?? [];
        if (! is_array($seed)) {
            $errors[] = "{$owner} `db.seed` must be an array of {collection, doc_id, body}.";

            return $errors;
        }

        $seedBytes = 0;
        foreach ($seed as $i => $row) {
            $where = "{$owner} `db.seed[{$i}]`";

            if (! is_array($row)) {
                $errors[] = "{$where} must be an object with `collection`, `doc_id` and `body`.";

                continue;
            }

            $rowUnknown = array_diff(array_keys($row), ['collection', 'doc_id', 'body']);
            if ($rowUnknown !== []) {
                $errors[] = "{$where} has unknown keys [".implode(', ', $rowUnknown).'].';
            }

            if (! isset($row['collection']) || ! is_string($row['collection']) || ! array_key_exists($row['collection'], $collections)) {
                $errors[] = "{$where} names an undeclared collection; seed rows may only use `db.collections`.";
            }

            if (! isset($row['doc_id']) || ! is_string($row['doc_id']) || ! self::isDocName($row['doc_id'], 128)) {
                $errors[] = "{$where} `doc_id` must be 1-128 characters of [A-Za-z0-9._-].";
            }

            if (! isset($row['body']) || ! is_array($row['body']) || array_is_list($row['body'])) {
                $errors[] = "{$where} `body` must be a JSON object.";
            } else {
                $bodyBytes = strlen((string) json_encode($row['body']));
                $seedBytes += $bodyBytes;

                if ($bodyBytes > self::MAX_BODY_BYTES) {
                    $errors[] = "{$where} `body` is {$bodyBytes} bytes; the per-row limit is ".self::MAX_BODY_BYTES.'.';
                }
            }
        }

        if ($seedBytes > self::MAX_SEED_BYTES) {
            $errors[] = "{$owner} seed totals {$seedBytes} bytes; the seed cap is ".self::MAX_SEED_BYTES.'.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function validateManifest(string $key, array $manifest): array
    {
        $errors = [];

        if (($manifest['key'] ?? null) !== $key) {
            $errors[] = "Template [{$key}] template.json `key` must equal its directory name.";
        }

        if (! isset($manifest['version']) || ! is_int($manifest['version']) || $manifest['version'] < 1) {
            $errors[] = "Template [{$key}] template.json `version` must be an integer of at least 1.";
        }

        foreach (['title', 'description'] as $field) {
            if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || trim($manifest[$field]) === '') {
                $errors[] = "Template [{$key}] template.json `{$field}` must be a non-empty string.";
            }
        }

        if (! isset($manifest['artifact_type']) || ! in_array($manifest['artifact_type'], ArtifactType::values(), true)) {
            $errors[] = "Template [{$key}] template.json `artifact_type` must be one of: ".implode(', ', ArtifactType::values()).'.';
        }

        if (! isset($manifest['entrypoint']) || ! is_string($manifest['entrypoint']) || $manifest['entrypoint'] === '' || str_starts_with($manifest['entrypoint'], '/')) {
            $errors[] = "Template [{$key}] template.json `entrypoint` must be a relative path under files/.";
        }

        if (! isset($manifest['sharing']) || ! in_array($manifest['sharing'], ['private', 'team', 'public'], true)) {
            $errors[] = "Template [{$key}] template.json `sharing` must be one of: private, team, public.";
        }

        if (! isset($manifest['edit_access']) || ! in_array($manifest['edit_access'], ['view', 'edit'], true)) {
            $errors[] = "Template [{$key}] template.json `edit_access` must be one of: view, edit.";
        }

        $requires = $manifest['requires'] ?? [];
        if (! is_array($requires) || array_is_list($requires) === false) {
            $errors[] = "Template [{$key}] template.json `requires` must be an array of feature keys.";
        } else {
            foreach ($requires as $feature) {
                if (! in_array($feature, self::KNOWN_FEATURES, true)) {
                    $errors[] = "Template [{$key}] requires unknown feature [{$feature}]; known features: ".implode(', ', self::KNOWN_FEATURES).'.';
                }
            }
        }

        if (array_key_exists('db', $manifest) && ! is_array($manifest['db'])) {
            $errors[] = "Template [{$key}] template.json `db` must be an object with `collections`.";
        }

        return $errors;
    }

    /** @return array<string, string>|null path => bytes, or null when files/ is missing */
    private function readFiles(string $dir): ?array
    {
        $filesDir = $dir.'/files';
        if (! is_dir($filesDir)) {
            return null;
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($filesDir) + 1);
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $path)] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function validateFiles(string $key, array $manifest, array $files): array
    {
        $errors = [];
        $where = "Template [{$key}]";

        $totalBytes = array_sum(array_map(strlen(...), array_values($files)));
        if ($totalBytes < 1 || $totalBytes > self::MAX_BUNDLE_BYTES) {
            $errors[] = "{$where} totals {$totalBytes} bytes; the bundle limit is ".self::MAX_BUNDLE_BYTES.'.';
        }

        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, '__artfct/')) {
                $errors[] = "{$where} file [{$path}] lives under the reserved `__artfct/` prefix.";
            }
        }

        if (! array_key_exists($manifest['entrypoint'], $files)) {
            $errors[] = "{$where} entrypoint [{$manifest['entrypoint']}] is not one of the files in files/.";
        }

        foreach ($files as $path => $bytes) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, ['html', 'css', 'js'], true)) {
                continue;
            }

            if ($extension === 'html' && preg_match('/<script\b(?![^>]*\bsrc\s*=)[^>]*>/i', $bytes) === 1) {
                $errors[] = "{$where} file [{$path}] has an inline `<script>`; put scripts in their own file and load them with `<script src=\"…\">`.";
            }

            if ($extension === 'html' && preg_match('/\son[a-z]+\s*=/i', $bytes) === 1) {
                $errors[] = "{$where} file [{$path}] has an `on*=` handler; move interactivity into a bundled script file.";
            }

            if (preg_match('/data:[a-z]+\/[a-z0-9.+-]+;/i', $bytes) === 1) {
                $errors[] = "{$where} file [{$path}] has a `data:` URI; put images and fonts in files/ and reference them by relative path.";
            }

            foreach ($this->externalOrigins($extension, $bytes) as $origin) {
                $errors[] = "{$where} file [{$path}] loads an external origin [{$origin}]; bundle every asset in files/.";
            }
        }

        $errors = array_merge($errors, $this->validateReferences($key, $files));

        return $errors;
    }

    /** @return list<string> the external origins a file loads, if any */
    private function externalOrigins(string $extension, string $bytes): array
    {
        $patterns = match ($extension) {
            'html' => [
                '/(?:src|href|action|poster|cite|data-[a-z-]+)\s*=\s*["\']\s*((?:https?:)?\/\/[^"\'\s<>]+)/i',
                '/url\(\s*["\']?\s*((?:https?:)?\/\/[^)"\'\s]+)/i',
            ],
            'css' => [
                '/url\(\s*["\']?\s*((?:https?:)?\/\/[^)"\'\s]+)/i',
                '/@import\s+["\']\s*((?:https?:)?\/\/[^"\'\s]+)/i',
            ],
            default => [
                // fetch/XHR/WebSocket/import/getAttribute-free string URLs.
                '/["\'`]((?:https?:)?\/\/[^"\'`\s]+)["\'`]/',
            ],
        };

        $origins = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $bytes, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $url) {
                $origins[] = $url;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * Every relative asset reference must resolve to a file in the bundle,
     * so a template cannot ship a page that 404s its own script or style.
     *
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function validateReferences(string $key, array $files): array
    {
        $errors = [];
        $where = "Template [{$key}]";

        foreach ($files as $path => $bytes) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $refs = [];

            if ($extension === 'html') {
                if (preg_match_all('/(?:src|href)\s*=\s*["\']([^"\'#<>]+)(?:#[^"\']*)?["\']/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }

                if (preg_match_all('/url\(\s*["\']?([^)"\'#]+)(?:#[^)"\']*)?["\']?\s*\)/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }
            } elseif ($extension === 'css') {
                if (preg_match_all('/url\(\s*["\']?([^)"\'#]+)(?:#[^)"\']*)?["\']?\s*\)/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }
            }

            foreach ($refs as $ref) {
                $ref = trim($ref);

                if ($ref === '' || str_starts_with($ref, 'data:') || preg_match('#^(?:https?:)?//#', $ref) === 1) {
                    continue;
                }

                if (str_starts_with($ref, '/')) {
                    $errors[] = "{$where} file [{$path}] references absolute path [{$ref}]; use a relative path inside files/.";

                    continue;
                }

                $resolved = $this->resolveRelative(dirname($path) === '.' ? '' : dirname($path), $ref);

                if ($resolved === null || ! array_key_exists($resolved, $files)) {
                    $errors[] = "{$where} file [{$path}] references [{$ref}], which is not in files/.";
                }
            }
        }

        return $errors;
    }

    private function resolveRelative(string $base, string $ref): ?string
    {
        $parts = $base === '' ? [] : explode('/', $base);

        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** @return list<string> */
    private function validateDb(string $key, mixed $db): array
    {
        if ($db === null) {
            return [];
        }

        return self::validateDbManifest($db, "Template [{$key}] template.json");
    }

    /** @return list<string> */
    private function validateSkill(string $key, string $dir): array
    {
        $where = "Template [{$key}]";
        $source = is_file($dir.'/skill/SKILL.md') ? (string) file_get_contents($dir.'/skill/SKILL.md') : null;
        $bundled = is_file($dir.'/files/SKILL.md') ? (string) file_get_contents($dir.'/files/SKILL.md') : null;

        if ($source === null) {
            return ["{$where} is missing skill/SKILL.md; the skill text is the single source the bundle and the guidance serve."];
        }

        if ($bundled === null) {
            return ["{$where} is missing files/SKILL.md; copy skill/SKILL.md byte-identical so the pulled page links the text it runs on."];
        }

        if ($source !== $bundled) {
            return ["{$where} skill/SKILL.md and files/SKILL.md differ; the bundle must carry the skill text byte-identical."];
        }

        return [];
    }

    /** Charset rule shared with the spec-20 transport limits. */
    private static function isDocName(string $value, int $max): bool
    {
        return $value !== '' && strlen($value) <= $max && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    /**
     * Load a template that already passed {@see validate()}; callers must
     * validate first, because this assumes the shapes hold.
     */
    private function load(string $key): TemplateDefinition
    {
        $dir = $this->basePath.'/'.$key;
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
        /** @var array<string, string> $files */
        $files = $this->readFiles($dir) ?? [];

        /** @var list<string> $requires */
        $requires = $manifest['requires'] ?? [];
        /** @var array{collections: array<string, array<string, mixed>>, seed?: list<array{collection: string, doc_id: string, body: array<string, mixed>}>}|null $db */
        $db = $manifest['db'] ?? null;
        if (is_array($db)) {
            $db['seed'] ??= [];
        }

        return new TemplateDefinition(
            key: $key,
            version: $manifest['version'],
            title: $manifest['title'],
            description: $manifest['description'],
            artifactType: $manifest['artifact_type'],
            entrypoint: $manifest['entrypoint'],
            sharing: $manifest['sharing'],
            editAccess: $manifest['edit_access'],
            files: $files,
            requires: $requires,
            db: $db,
            skill: is_file($dir.'/skill/SKILL.md') ? (string) file_get_contents($dir.'/skill/SKILL.md') : null,
        );
    }
}
