<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation policy
    |--------------------------------------------------------------------------
    |
    | Members can invite by default (teams grow bottom-up). Turn
    | `members_can_invite` off to make invitations admin-only, which limits
    | who can send email from our sending domain. `max_pending_invitations`
    | caps unaccepted invitations per team; the hourly send limit lives in the
    | `invitations` rate limiter.
    |
    */

    'members_can_invite' => (bool) env('TEAMS_MEMBERS_CAN_INVITE', true),

    'max_pending_invitations' => (int) env('TEAMS_MAX_PENDING_INVITATIONS', 25),

];
