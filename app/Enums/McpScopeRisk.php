<?php

namespace App\Enums;

/**
 * What a scope can do to a workspace, used to mark risk-sensitive scopes on
 * the consent screen. The risk lives beside the scope definition rather than
 * in the UI so a new scope cannot be shown without one.
 */
enum McpScopeRisk: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';
}
