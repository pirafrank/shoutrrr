<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CloneThreadsPostToMastodon implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 86400;

    public function __construct(public readonly string $targetId) {}

    public function uniqueId(): string
    {
        return $this->targetId;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $target = PostTarget::query()
            ->with('post')
            ->whereKey($this->targetId)
            ->firstOrFail();

        if ($target->platform !== Platform::Threads || $target->status !== PostTargetStatus::Published) {
            return;
        }

        $url = (string) config('services.mastodon.internal_webhook_url');
        $secret = (string) config('services.mastodon.internal_webhook_secret');

        if ($url === '' || $secret === '') {
            Log::notice('Mastodon clone skipped because the internal webhook is not configured.', [
                'threads_target_id' => $target->id,
            ]);

            return;
        }

        $segments = array_values(array_filter(
            array_map(static fn (mixed $segment): string => trim((string) $segment), $target->sections),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            Log::warning('Mastodon clone skipped because the Threads target has no text segments.', [
                'threads_target_id' => $target->id,
            ]);

            return;
        }

        $remoteIds = $target->mastodon_clone_remote_ids ?? [];
        $parentId = null;

        foreach ($segments as $index => $segment) {
            if (isset($remoteIds[$index])) {
                $parentId = (string) $remoteIds[$index];

                continue;
            }

            $response = $this->request($secret)
                ->post($url, [
                    'id' => "threads-target:{$target->id}:{$index}",
                    'source' => [
                        'post_id' => $target->post_id,
                        'threads_target_id' => $target->id,
                        'segment_index' => $index,
                    ],
                    'status' => $segment,
                    'segments' => $segments,
                    'visibility' => 'public',
                    'language' => 'en',
                    'scheduled_at' => null,
                    'in_reply_to_id' => $parentId,
                    'media' => [],
                ]);

            if ($response->failed()) {
                throw new RuntimeException("Mastodon clone webhook returned HTTP {$response->status()}.");
            }

            $remoteId = (string) $response->json('id');
            if ($remoteId === '') {
                throw new RuntimeException('Mastodon clone webhook returned no status id.');
            }

            $remoteIds[$index] = $remoteId;
            $target->forceFill(['mastodon_clone_remote_ids' => array_values($remoteIds)])->save();
            $parentId = $remoteId;
        }

        Log::info('Threads post sent to the internal Mastodon clone webhook.', [
            'threads_target_id' => $target->id,
            'segments' => count($segments),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Threads-to-Mastodon clone failed after retries.', [
            'threads_target_id' => $this->targetId,
            'exception' => $exception?->getMessage(),
        ]);
    }

    private function request(string $secret): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->withHeaders(['X-Internal-Webhook-Secret' => $secret])
            ->connectTimeout(5)
            ->timeout(20);
    }
}
