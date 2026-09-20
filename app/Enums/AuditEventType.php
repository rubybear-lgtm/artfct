<?php

namespace App\Enums;

/**
 * Every audit event type spec 11 names. Backed by the wire string used in
 * SIEM export rows and mirrored by the Worker's `governance::AuditEventType`
 * (`backend/src/governance.rs`) for the artifact-scoped events the Worker
 * writes directly on its own hot path (`artifact.viewed` above all).
 */
enum AuditEventType: string
{
    case ArtifactCreated = 'artifact.created';
    case ArtifactViewed = 'artifact.viewed';
    case ArtifactShared = 'artifact.shared';
    case ArtifactRevoked = 'artifact.revoked';
    case ArtifactDeleted = 'artifact.deleted';
    case ShareCreated = 'share.created';
    case ShareRevoked = 'share.revoked';
    case MemberAdded = 'member.added';
    case MemberRemoved = 'member.removed';
    case RoleChanged = 'role.changed';
    case TokenCreated = 'token.created';
    case TokenRevoked = 'token.revoked';
    case AuthModeChanged = 'auth_mode.changed';
    case ExportPerformed = 'export.performed';
    case RetentionApplied = 'retention.applied';
    case LegalHoldApplied = 'legal_hold.applied';
    case SearchPerformed = 'search.performed';
    case OwnershipTransferred = 'owner.transferred';
    case SeatsSynced = 'billing.seats_synced';
    case AccountDeleted = 'account.deleted';
    case SubscriptionCancelled = 'billing.subscription_cancelled';
    case SubscriptionResumed = 'billing.subscription_resumed';
}
