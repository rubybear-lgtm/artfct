<?php

namespace App\Services\Slack;

/**
 * An artifact's sharing configuration (spec 11's share model), the axis
 * unfurl richness scales on (spec 15). "Public" here covers both the
 * free-tier `/p/{id}` link and a passcode-protected share link — both are
 * meant to be viewable by whoever holds the URL.
 */
enum SharingLevel
{
    case Public;
    case DomainRestricted;
    case OrgPrivate;
}
