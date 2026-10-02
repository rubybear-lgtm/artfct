<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    /** Changing `auth_mode` is gated separately from ordinary team updates. */
    case ChangeAuthMode = 'team:change-auth-mode';

    /** Retention policy, legal hold, and GDPR erasure (spec 11). */
    case ManageGovernance = 'team:manage-governance';

    /** Billing checkout, seat sync, and custom hostname (spec 14). */
    case ManageBilling = 'team:manage-billing';

    /** Pinning a collection as canonical (spec 16) — creating/editing a collection needs no permission at all; any member can. */
    case PinCanonicalCollection = 'collection:pin-canonical';
}
