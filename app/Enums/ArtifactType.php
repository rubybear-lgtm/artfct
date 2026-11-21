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
    /** A team context post: a decision, announcement or brand note people and bots ask about. Not the marketing blog's BlogPost. */
    case Post = 'post';
    /** A team's design system: tokens and components that publish skills consume. */
    case DesignSystem = 'design_system';
    case Other = 'other';

    /**
     * The name people see for the type, in sentence case: "Design system".
     */
    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * Every type as a select option, so a filter stays in step with the enum.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
