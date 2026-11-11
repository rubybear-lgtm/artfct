<?php

namespace App\Notifications\Teams;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialEndingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Team $team,
        public int $daysLeft,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = max(1, $this->daysLeft);

        return (new MailMessage)
            ->subject(__('Your Artfct Team trial ends in :days days', ['days' => $days]))
            ->line(__('The Team trial for :team ends in :days days. After that the workspace drops back to Free — no charge, nothing deleted.', [
                'team' => $this->team->name,
                'days' => $days,
            ]))
            ->line(__('Add a card to keep Pro or Team, or keep using Free.'))
            ->action(
                __('Manage billing'),
                route('teams.billing.show', ['team' => $this->team->slug]),
            );
    }

    /**
     * @return array{team_id: int, days_left: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->team->id,
            'days_left' => $this->daysLeft,
        ];
    }
}
