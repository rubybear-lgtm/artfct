<?php

namespace App\Enums;

/**
 * Spec 16's automatic ranking signals.
 */
enum UsageEventType: string
{
    case Viewed = 'viewed';
    case SlackShared = 'slack_shared';
    case RetrievedThenOpened = 'retrieved_then_opened';
    /** Recorded on the OLD artifact; `related_artifact_id` names the successor. */
    case Superseded = 'superseded';
}
