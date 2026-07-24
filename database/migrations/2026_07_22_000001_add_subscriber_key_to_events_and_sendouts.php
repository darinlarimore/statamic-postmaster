<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes tracking events subscriber-store-agnostic. Under the content driver a
 * subscriber has no integer id, so events are keyed by subscriber_key (the
 * deterministic sha1(email) hash, or the (string) legacy id). The old
 * subscriber_id FK is dropped so string keys don't violate it.
 *
 * Also adds:
 * - sendouts.recipient_keys — the frozen candidate set captured at send start.
 * - sendouts.skip_count — recipients suppressed mid-send (unsubscribed/bounced),
 *   distinct from failures so they don't trip the abort threshold.
 * - events.terminal_key — "sendout_id-subscriber_key" for terminal events only
 *   (sent/failed/skipped), with a unique index, so a duplicate terminal insert
 *   throws rather than silently double-recording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postmaster_events', function (Blueprint $table): void {
            $table->string('subscriber_key')->nullable()->after('subscriber_id')->index();
            $table->string('terminal_key')->nullable()->after('type');
        });

        DB::table('postmaster_events')
            ->whereNull('subscriber_key')
            ->whereNotNull('subscriber_id')
            ->update(['subscriber_key' => DB::raw('subscriber_id')]);

        Schema::table('postmaster_events', function (Blueprint $table): void {
            $table->dropForeign(['subscriber_id']);
        });

        Schema::table('postmaster_events', function (Blueprint $table): void {
            $table->unique('terminal_key', 'postmaster_events_terminal_unique');
        });

        Schema::table('postmaster_sendouts', function (Blueprint $table): void {
            $table->json('recipient_keys')->nullable()->after('list_ids');
            $table->unsignedInteger('skip_count')->default(0)->after('fail_count');
        });
    }

    public function down(): void
    {
        Schema::table('postmaster_sendouts', function (Blueprint $table): void {
            $table->dropColumn(['recipient_keys', 'skip_count']);
        });

        Schema::table('postmaster_events', function (Blueprint $table): void {
            $table->dropUnique('postmaster_events_terminal_unique');
            $table->dropColumn(['subscriber_key', 'terminal_key']);
            $table->foreign('subscriber_id')
                ->references('id')
                ->on('postmaster_subscribers')
                ->cascadeOnDelete();
        });
    }
};
