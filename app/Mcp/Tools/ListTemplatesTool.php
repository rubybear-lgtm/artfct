<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\FeatureFlaggedTool;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpTelemetry;
use App\Services\FeatureFlags;
use App\Services\Templates\TemplateCatalog;
use App\Services\Templates\TemplateFeatures;
use App\Services\Templates\TemplateListing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Use when the person asks what starter boards or templates they can pull in, such as a kanban, wiki or announcements board. Rules in get_guidance (topic templates) apply even if you have not read them. Each row says whether the team can pull it now; a template whose required features are not released cannot be pulled yet. Then use pull_template to add one to the library.')]
#[Name('list_templates')]
#[Title('List templates')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class ListTemplatesTool extends FeatureFlaggedTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.10.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:read'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'See which templates the team can pull.',
                'arguments' => [],
            ]],
        ],
    ];

    public function shouldRegister(): bool
    {
        try {
            return parent::shouldRegister();
        } catch (AuthorizationException) {
            return false;
        }
    }

    protected function featureKey(): string
    {
        return TemplateFeatures::ARTIFACT_TEMPLATES;
    }

    public function handle(TemplateCatalog $catalog, FeatureFlags $features): ResponseFactory
    {
        $startedAt = hrtime(true);

        if (($refused = $this->featureNotEnabled($startedAt)) !== null) {
            return $refused;
        }

        McpContext::requireScope('artifacts:read', 'list_templates');

        $templates = TemplateListing::summaries($catalog, $features, McpContext::team());

        app(McpTelemetry::class)->record('list_templates', 'success', $startedAt);

        return Response::structured(['templates' => $templates]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
