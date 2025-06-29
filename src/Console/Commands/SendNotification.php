<?php

namespace FinnWiel\ShazzooNotify\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use FinnWiel\ShazzooNotify\Events\NotificationEvent;
use FinnWiel\ShazzooNotify\Models\ExpoToken;
use FinnWiel\ShazzooNotify\Models\NotificationType;
use FinnWiel\ShazzooNotify\Models\RegisteredDevice;
use Illuminate\Support\Facades\Http;

class SendNotification extends Command
{
    protected $signature = 'shazzoo:notify
                            {--user= : Email or ID of a user}
                            {--type= : Notification type slug (e.g. new_message)}
                            {--title= : Notification title}
                            {--body= : Notification body}
                            {--data= : JSON payload to send (optional)}';

    protected $description = 'Send an Expo push notification to a user or all users with preferences respected';

    public function handle()
    {
        $this->info('Sending notifications...');

        $type = $this->option('type');
        $title = $this->option('title', 'Notification');
        $body = $this->option('body', '');
        $extraData = json_decode($this->option('data', '{}'), true);

        if (! $type) {
            return $this->error('Please specify a notification type with --type');
        }

        // If user option is provided, send only to that user (filtered tokens)
        if ($this->option('user')) {
            $user = User::where('email', $this->option('user'))
                ->orWhere('id', $this->option('user'))
                ->first();

            if (! $user) {
                return $this->error("User not found.");
            }

            // Get tokens for this user with the notification type enabled
            $expoTokens = $user->expoTokens()
                ->whereHas('notificationPreferences', function ($query) use ($type) {
                    $query->where('enabled', true)
                        ->whereHas('notificationType', function ($q) use ($type) {
                            $q->where('name', $type);
                        });
                })
                ->get();
        } else {
            // Get all tokens with notification type enabled
            $expoTokens = RegisteredDevice::whereHas('notificationPreferences', function ($query) use ($type) {
                $query->where('enabled', true)
                    ->whereHas('notificationType', function ($q) use ($type) {
                        $q->where('name', $type);
                    });
            })->get();
        }

        if ($expoTokens->isEmpty()) {
            return $this->info('No devices found with that notification type enabled.');
        }

        foreach ($expoTokens as $token) {
            $payload = [
                'to' => $token->token,
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                "priority" => "high",
                "channelId" => "default",
                'android' => [
                    'smallIcon' => 'notification-icon',
                    'color' => '#ed751f',
                ],

            ];

            Http::post('https://exp.host/--/api/v2/push/send', $payload);
            $this->line("✓ Sent to {$token->token}");
        }

        event(new NotificationEvent(
            $type,
            [
                'title' => $title,
                'body' => $body,
                'extra' => $extraData,
            ]
        ));

        $this->info("Done.");
    }
}
