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
    case ArtifactDeployed = 'artifact.deployed';
    case ArtifactViewed = 'artifact.viewed';
    case ArtifactShared = 'artifact.shared';
    case ArtifactSharingChanged = 'artifact.sharing_changed';
    case ArtifactRevoked = 'artifact.revoked';
    case ArtifactLinkMinted = 'artifact.link_minted';
    case ArtifactDeleted = 'artifact.deleted';
    case ArtifactVersionRestored = 'artifact.version_restored';
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
    case InvitationDeclined = 'invitation.declined';
    case TeamCreated = 'team.created';
    case TeamRenamed = 'team.renamed';
    case TeamDeleted = 'team.deleted';
    case MemberLeft = 'member.left';
    case PublicSharingChanged = 'public_sharing.changed';
    case SubscriptionCancelled = 'billing.subscription_cancelled';
    case SubscriptionResumed = 'billing.subscription_resumed';
    case McpConnectionCreated = 'mcp.connection_created';
    case McpConnectionRefreshed = 'mcp.connection_refreshed';
    case McpConnectionRevoked = 'mcp.connection_revoked';
    case McpConnectionReauthorized = 'mcp.connection_reauthorized';

    /**
     * Plain sentence-case label for admins who are not engineers, shown in
     * the audit log in place of the wire value. Deliberately has no
     * `default` arm: a new case has to be labelled here.
     */
    public function label(): string
    {
        return match ($this) {
            self::ArtifactCreated => 'Artifact created',
            self::ArtifactDeployed => 'Artifact deployed',
            self::ArtifactViewed => 'Artifact viewed',
            self::ArtifactShared => 'Artifact shared',
            self::ArtifactSharingChanged => 'Artifact sharing changed',
            self::ArtifactRevoked => 'Artifact revoked',
            self::ArtifactLinkMinted => 'Open link created',
            self::ArtifactDeleted => 'Artifact deleted',
            self::ArtifactVersionRestored => 'Artifact version restored',
            self::ShareCreated => 'Share created',
            self::ShareRevoked => 'Share revoked',
            self::MemberAdded => 'Member added',
            self::MemberRemoved => 'Member removed',
            self::RoleChanged => 'Role changed',
            self::TokenCreated => 'Token created',
            self::TokenRevoked => 'Token revoked',
            self::AuthModeChanged => 'Sign-in method changed',
            self::ExportPerformed => 'Audit log exported',
            self::RetentionApplied => 'Retention applied',
            self::LegalHoldApplied => 'Legal hold placed',
            self::SearchPerformed => 'Search run',
            self::OwnershipTransferred => 'Ownership transferred',
            self::SeatsSynced => 'Seats updated',
            self::AccountDeleted => 'Account deleted',
            self::InvitationDeclined => 'Invitation declined',
            self::TeamCreated => 'Team created',
            self::TeamRenamed => 'Team renamed',
            self::TeamDeleted => 'Team deleted',
            self::MemberLeft => 'Member left',
            self::PublicSharingChanged => 'Public links changed',
            self::SubscriptionCancelled => 'Subscription cancelled',
            self::SubscriptionResumed => 'Subscription resumed',
            self::McpConnectionCreated => 'AI tool connected',
            self::McpConnectionRefreshed => 'AI tool connection refreshed',
            self::McpConnectionRevoked => 'AI tool disconnected',
            self::McpConnectionReauthorized => 'AI tool reconnected',
        };
    }
}
