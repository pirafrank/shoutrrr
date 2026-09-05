<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->json('mastodon_clone_remote_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn('mastodon_clone_remote_ids');
        });
    }
};
