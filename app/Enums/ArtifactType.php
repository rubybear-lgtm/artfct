<?php

namespace App\Enums;

/**
 * What kind of thing an artifact is, so people and AI tools can filter by it.
 */
enum ArtifactType: string
{
    case Report = 'report';
    case Table = 'table';
    case Document = 'document';
    case Mockup = 'mockup';
    case SlideDeck = 'slide_deck';
    case Dashboard = 'dashboard';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
