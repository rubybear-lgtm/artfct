<?php

namespace App\Services\Templates;

/**
 * Feature keys for the template surface (spec 21). The database key itself
 * lives on the db tools' base (`DatabaseTool::FEATURE`); this file owns only
 * the template-surface key so both stay stringly-typed in one place each.
 */
final class TemplateFeatures
{
    public const ARTIFACT_TEMPLATES = 'artifact-templates';
}
