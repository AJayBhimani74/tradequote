<?php

namespace App\Notifications;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quote $quote) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New quote request #{$this->quote->id}")
            ->line("{$this->quote->buyer->name} requested a quote for {$this->quote->items()->count()} item(s).")
            ->line('Log in to price the request.');
    }
}
