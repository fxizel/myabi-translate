<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyDigest extends Notification
{
    public function __construct(public array $counts, public string $language = 'de')
    {
        // The daily command confirms SMTP delivery before advancing its watermark.
        // Dispatching after commit would lose a retry when synchronous SMTP fails.
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $notifiable->refresh();

        return $notifiable->active && ! $notifiable->is_technical && $notifiable->notifications_enabled
            && ($notifiable->organization?->active ?? false);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = match ($this->language) {
            'fr' => ['title' => 'ARGE-ABI · Résumé quotidien', 'greeting' => 'Bonjour', 'open' => 'Ouvrir le Référentiel',
                'footer' => 'Vous pouvez désactiver ce résumé dans votre profil.',
                'pending' => 'Propositions en attente', 'overdue' => 'Dont sans traitement depuis 30 jours',
                'validated' => 'Vos propositions validées', 'rejected' => 'Vos propositions rejetées',
                'imports' => 'Imports terminés', 'failed_imports' => 'Imports en échec',
                'obsolete' => 'Termes devenus obsolètes', 'review' => 'Termes à réviser'],
            'it' => ['title' => 'ARGE-ABI · Riepilogo giornaliero', 'greeting' => 'Buongiorno', 'open' => 'Aprire il repertorio',
                'footer' => 'È possibile disattivare questo riepilogo nel profilo.',
                'pending' => 'Proposte in attesa', 'overdue' => 'Di cui senza elaborazione da 30 giorni',
                'validated' => 'Le vostre proposte convalidate', 'rejected' => 'Le vostre proposte rifiutate',
                'imports' => 'Importazioni completate', 'failed_imports' => 'Importazioni non riuscite',
                'obsolete' => 'Termini diventati obsoleti', 'review' => 'Termini da revisionare'],
            default => ['title' => 'ARGE-ABI · Tagesübersicht', 'greeting' => 'Guten Tag', 'open' => 'Übersetzungsregister öffnen',
                'footer' => 'Sie können diese Übersicht in Ihrem Profil deaktivieren.',
                'pending' => 'Ausstehende Vorschläge', 'overdue' => 'Davon seit 30 Tagen unbearbeitet',
                'validated' => 'Ihre freigegebenen Vorschläge', 'rejected' => 'Ihre abgelehnten Vorschläge',
                'imports' => 'Abgeschlossene Importe', 'failed_imports' => 'Fehlgeschlagene Importe',
                'obsolete' => 'Veraltete Begriffe', 'review' => 'Zu prüfende Begriffe'],
        };
        $mail = (new MailMessage)->subject($text['title'])->greeting($text['greeting'].' '.$notifiable->name.',');
        foreach ($this->counts as $key => $count) {
            $mail->line(($text[$key] ?? $key).': '.number_format($count, 0, '.', ' '));
        }

        return $mail->action($text['open'], url('/'))->line($text['footer']);
    }
}
