<?php

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\CloneThreadsPostToMastodon;
use Illuminate\Support\Facades\Http;

test('sends a published Threads target to the internal Mastodon webhook', function () {
    $target = publishTarget(['first segment', 'second segment']);
    $target->forceFill([
        'platform' => Platform::Threads->value,
        'status' => PostTargetStatus::Published->value,
    ])->save();
    $target->account()->update(['platform' => Platform::Threads->value]);

    config([
        'services.mastodon.internal_webhook_url' => 'http://social-n8n:5678/webhook/mastodon-publish',
        'services.mastodon.internal_webhook_secret' => 'test-secret',
    ]);
    Http::fake(function ($request) {
        return Http::response([
            'id' => 'mastodon-'.$request['source']['segment_index'],
        ]);
    });

    (new CloneThreadsPostToMastodon($target->id))->handle();

    Http::assertSent(function ($request) use ($target): bool {
        return $request->url() === 'http://social-n8n:5678/webhook/mastodon-publish'
            && $request->hasHeader('X-Internal-Webhook-Secret', 'test-secret')
            && $request['source']['threads_target_id'] === $target->id;
    });
    Http::assertSentCount(2);
    $target->refresh();
    expect($target->mastodon_clone_remote_ids)->toBe(['mastodon-0', 'mastodon-1']);
});

test('fails independently when the internal Mastodon webhook rejects the request', function () {
    $target = publishTarget();
    $target->forceFill([
        'platform' => Platform::Threads->value,
        'status' => PostTargetStatus::Published->value,
    ])->save();
    $target->account()->update(['platform' => Platform::Threads->value]);

    config([
        'services.mastodon.internal_webhook_url' => 'http://social-n8n:5678/webhook/mastodon-publish',
        'services.mastodon.internal_webhook_secret' => 'test-secret',
    ]);
    Http::fake([
        'http://social-n8n:5678/webhook/mastodon-publish' => Http::response([], 503),
    ]);

    expect(fn () => (new CloneThreadsPostToMastodon($target->id))->handle())
        ->toThrow(RuntimeException::class, 'Mastodon clone webhook returned HTTP 503.');
});
