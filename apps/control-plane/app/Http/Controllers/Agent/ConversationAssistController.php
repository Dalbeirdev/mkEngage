<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agent AI assist for the conversation sidebar: an AI-drafted suggested reply
 * plus a lightweight summary and sentiment. On-demand only (an agent clicks
 * "Suggest") so there's no spurious AI spend. The suggested reply is drafted
 * by the same AI service the chatbot uses; summary and sentiment are derived
 * server-side from the visitor's own messages.
 */
final class ConversationAssistController extends Controller
{
    private const POSITIVE = ['thanks', 'thank you', 'great', 'awesome', 'perfect', 'love', 'happy', 'excellent', 'good', 'appreciate'];

    private const NEGATIVE = ['angry', 'terrible', 'bad', 'worst', 'broken', 'not working', 'refund', 'cancel', 'frustrated', 'useless', 'disappointed', 'error', 'issue', 'problem'];

    public function suggest(string $conversationId): JsonResponse
    {
        $conversation = Conversation::query()->find($conversationId);
        abort_if($conversation === null, 404);

        /** @var list<array{sender_type: string, body: string}> $history */
        $history = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('sequence_number')
            ->limit(30)
            ->get(['sender_type', 'body', 'content_type'])
            ->map(fn (Message $message): array => [
                'sender_type' => $message->sender_type,
                'body' => $this->plainText($message),
            ])
            ->all();

        $customerMessages = array_values(array_filter(
            $history,
            fn (array $entry): bool => in_array($entry['sender_type'], ['visitor', 'contact'], true),
        ));

        return response()->json([
            'suggested_reply' => $this->draftReply($conversation, $history),
            'summary' => $this->summarize($customerMessages),
            'sentiment' => $this->sentiment($customerMessages),
        ]);
    }

    /** @param list<array{sender_type: string, body: string}> $history */
    private function draftReply(Conversation $conversation, array $history): ?string
    {
        if ($history === []) {
            return null;
        }

        $chatbot = Chatbot::query()->where('status', 'active')->first();
        $chatbotName = $chatbot !== null ? $chatbot->name : 'Assistant';
        $provider = $chatbot !== null ? $chatbot->provider : 'fake';
        $model = $chatbot !== null ? $chatbot->model : null;
        $url = config('services.ai.url');
        $token = config('services.ai.token');
        $timeout = config('services.ai.timeout', 25);

        try {
            $response = Http::withToken(is_string($token) ? $token : '')
                ->timeout(is_numeric($timeout) ? (int) $timeout : 25)
                ->acceptJson()
                ->post(rtrim(is_string($url) ? $url : '', '/').'/v1/reply', [
                    'organization_id' => (string) $conversation->organization_id,
                    'conversation_id' => $conversation->id,
                    'chatbot_name' => $chatbotName,
                    'system_prompt' => 'You assist a human support agent. Draft one concise, friendly reply the agent can send next. Reply with the message text only.',
                    'history' => $history,
                    'context_chunks' => [],
                    'config' => [
                        'provider' => $provider,
                        'model' => $model,
                    ],
                ]);
        } catch (\Throwable) {
            Log::warning('assist_suggest_failed', ['conversation_id' => $conversation->id, 'reason' => 'transport']);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $body = $response->json('body');

        return is_string($body) && trim($body) !== '' ? trim($body) : null;
    }

    /** @param list<array{sender_type: string, body: string}> $customerMessages */
    private function summarize(array $customerMessages): ?string
    {
        if ($customerMessages === []) {
            return null;
        }

        $joined = trim(implode(' ', array_map(
            fn (array $entry): string => $entry['body'],
            array_slice($customerMessages, -3),
        )));

        return $joined === '' ? null : 'Visitor said: '.Str::limit($joined, 180);
    }

    /**
     * Keyword sentiment over the customer's own words — a transparent heuristic
     * (not model-based); "neutral" when signals are absent or balanced.
     *
     * @param  list<array{sender_type: string, body: string}>  $customerMessages
     */
    private function sentiment(array $customerMessages): string
    {
        $text = mb_strtolower(implode(' ', array_map(
            fn (array $entry): string => $entry['body'],
            $customerMessages,
        )));

        $positive = 0;
        $negative = 0;
        foreach (self::POSITIVE as $word) {
            $positive += substr_count($text, $word);
        }
        foreach (self::NEGATIVE as $word) {
            $negative += substr_count($text, $word);
        }

        if ($positive > $negative) {
            return 'positive';
        }
        if ($negative > $positive) {
            return 'negative';
        }

        return 'neutral';
    }

    private function plainText(Message $message): string
    {
        if ($message->content_type === 'rich') {
            $decoded = json_decode($message->body, true);
            if (is_array($decoded) && is_string($decoded['text'] ?? null)) {
                return $decoded['text'];
            }
        }

        return $message->body;
    }
}
