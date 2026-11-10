<?php

namespace App\Enums;

/**
 * Optional "How did you hear about Artfct?" answers on web team create/upgrade.
 */
enum HeardAboutSource: string
{
    case LinkedIn = 'linkedin';
    case X = 'x';
    case SearchEngine = 'search_engine';
    case FriendOrColleague = 'friend_or_colleague';
    case AiAssistant = 'ai_assistant';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn',
            self::X => 'X (Twitter)',
            self::SearchEngine => 'Search engine',
            self::FriendOrColleague => 'Friend or colleague',
            self::AiAssistant => 'AI assistant / bot marketplace (Grok, Cursor, ChatGPT, Claude)',
            self::Other => 'Other',
        };
    }
}
