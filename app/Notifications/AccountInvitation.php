<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $url)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your ARGE-ABI account invitation'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An administrator has invited you to the ARGE-ABI translation repository.'))
            ->action(__('Activate my account'), $this->url)
            ->line(__('This single-use invitation expires in 72 hours.'));
    }
}
