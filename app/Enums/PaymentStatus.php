<?php

namespace App\Enums;

/**
 * spec 14: "Payment failure degrades to read-only: artifacts keep
 * serving, new creates are refused." `PastDue` never triggers deletion or
 * a serving outage — only blocks new artifact creation.
 */
enum PaymentStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
}
