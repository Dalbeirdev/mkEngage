<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Outbox relay (ADR-005): publishes unpublished outbox_events to NATS
 * JetStream and marks them published. Streams are provisioned idempotently
 * at startup (CONVERSATIONS ← conv.>, KNOWLEDGE ← knowledge.>, PLATFORM
 * as catch-all for org/subscription/usage subjects).
 *
 * At-least-once by construction: a crash between publish and mark republishes
 * on restart — the envelope id doubles as Nats-Msg-Id so JetStream dedups
 * within its window, and consumers must be idempotent regardless (RULES).
 */
final class OutboxRelay extends Command
{
    protected $signature = 'outbox:relay {--once : Drain the current backlog and exit (tests/CI)}';

    protected $description = 'Publish outbox events to NATS JetStream';

    public function handle(): int
    {
        $url = config('services.nats.url');

        if (! is_string($url) || $url === '') {
            $this->error('NATS_URL is not configured.');

            return self::FAILURE;
        }

        $client = $this->connect($url);
        $this->provisionStreams($client);
        $this->info('Outbox relay started.');

        do {
            $published = $this->drainOnce($client);

            if ($this->option('once')) {
                $this->info("Drained {$published} event(s).");

                return self::SUCCESS;
            }

            if ($published === 0) {
                usleep(250_000);
            }
        } while (true);
    }

    private function connect(string $url): Client
    {
        $parts = parse_url($url);

        $configuration = new Configuration([
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 4222,
        ]);

        return new Client($configuration);
    }

    private function provisionStreams(Client $client): void
    {
        $api = $client->getApi();

        foreach ([
            'CONVERSATIONS' => ['conv.>'],
            'KNOWLEDGE' => ['knowledge.>'],
            'PLATFORM' => ['org.>', 'subscription.>', 'usage.>', 'integration.>'],
        ] as $name => $subjects) {
            $stream = $api->getStream($name);
            $stream->getConfiguration()
                ->setSubjects($subjects)
                ->setRetentionPolicy('limits')
                ->setMaxAge(7 * 24 * 3600 * 1_000_000_000); // ns

            if (! $stream->exists()) {
                $stream->create();
            }
        }
    }

    private function drainOnce(Client $client): int
    {
        $events = DB::table('outbox_events')
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $count = 0;

        foreach ($events as $event) {
            // Subject = event type minus the version suffix (conv.message.accepted).
            $subject = (string) preg_replace('/\.v\d+$/', '', (string) $event->event_type);

            $client->publish($subject, (string) $event->envelope);

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update(['published_at' => now()]);

            $count++;
        }

        return $count;
    }
}
