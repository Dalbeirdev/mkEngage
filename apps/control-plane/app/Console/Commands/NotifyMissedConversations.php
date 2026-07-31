<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Agent\NotificationSettingsController;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Emails agents about open conversations whose latest message is from the
 * customer and has been waiting past the org's configured threshold.
 * Scheduled every minute (routes/console.php); each waiting message
 * triggers at most one email (conversations.missed_notified_sequence).
 */
final class NotifyMissedConversations extends Command
{
    protected $signature = 'notifications:missed';

    protected $description = 'Email agents about conversations awaiting a reply past the configured threshold.';

    public function handle(Tenancy $tenancy): int
    {
        Organization::query()->orderBy('id')->chunk(50, function ($organizations) use ($tenancy): void {
            foreach ($organizations as $organization) {
                $config = NotificationSettingsController::contract($organization);
                if ($config['missed_email_enabled'] !== true) {
                    continue;
                }

                $tenancy->run($organization->id, function () use ($organization, $config): void {
                    $this->processOrganization($organization, $config['missed_after_minutes']);
                });
            }
        });

        return self::SUCCESS;
    }

    private function processOrganization(Organization $organization, int $afterMinutes): void
    {
        $candidates = Conversation::query()
            ->where('status', 'open')
            ->where('is_spam', false)
            ->whereColumn('last_sequence', '>', 'missed_notified_sequence')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        foreach ($candidates as $conversation) {
            $last = Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('sequence_number')
                ->first();

            if ($last === null) {
                continue;
            }

            // Latest word is ours (agent/bot) — nothing is waiting; remember
            // the sequence so this conversation stops matching until the
            // customer writes again.
            if (! in_array($last->sender_type, ['visitor', 'contact'], true)) {
                $conversation->missed_notified_sequence = (int) $conversation->last_sequence;
                $conversation->save();

                continue;
            }

            if ($last->sent_at === null || $last->sent_at->gt(now()->subMinutes($afterMinutes))) {
                continue; // Still inside the response window — check again next run.
            }

            $recipients = $this->recipients($conversation);
            if ($recipients !== []) {
                $this->sendMail($organization, $conversation, (string) $last->body, $recipients, $afterMinutes);
            }

            $conversation->missed_notified_sequence = (int) $conversation->last_sequence;
            $conversation->save();
        }
    }

    /** @return list<string> agent email addresses */
    private function recipients(Conversation $conversation): array
    {
        if ($conversation->assigned_agent_id !== null) {
            $email = User::query()->whereKey($conversation->assigned_agent_id)
                ->where('status', 'active')->value('email');

            if (is_string($email) && $email !== '') {
                return [$email];
            }
        }

        $query = User::query()->where('status', 'active');

        if ($conversation->department_id !== null) {
            $departmentQuery = (clone $query)->whereHas(
                'departments',
                fn ($q) => $q->whereKey($conversation->department_id),
            );
            $emails = $departmentQuery->limit(10)->pluck('email');
            if ($emails->isNotEmpty()) {
                return array_values(array_filter($emails->all(), 'is_string'));
            }
        }

        return array_values(array_filter($query->limit(10)->pluck('email')->all(), 'is_string'));
    }

    /** @param list<string> $recipients */
    private function sendMail(
        Organization $organization,
        Conversation $conversation,
        string $preview,
        array $recipients,
        int $afterMinutes,
    ): void {
        $base = config('app.dashboard_url');
        $base = is_string($base) && $base !== '' ? rtrim($base, '/') : '';
        $link = $base.'/conversations/'.$conversation->id;
        $snippet = Str::limit(trim($preview), 120);
        $organizationName = $organization->name;

        Mail::raw(
            "A customer in \"{$organizationName}\" has been waiting more than {$afterMinutes} minutes for a reply.\n\n"
            ."Their last message:\n\"{$snippet}\"\n\n"
            ."Open the conversation:\n{$link}\n",
            function ($message) use ($recipients): void {
                $message->to($recipients)->subject('A customer is waiting for a reply');
            }
        );
    }
}
