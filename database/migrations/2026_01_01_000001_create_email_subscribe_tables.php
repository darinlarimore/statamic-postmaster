<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_subscribe_lists', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->boolean('double_opt_in')->nullable(); // null = use config default
            $table->timestamps();
        });

        Schema::create('email_subscribe_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->json('fields')->nullable(); // arbitrary custom fields
            $table->string('status')->default('pending'); // pending, subscribed, unsubscribed, bounced, complained
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->string('source')->nullable(); // form, import, api
            $table->string('subscribe_ip', 45)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('email_subscribe_list_subscriber', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('email_subscribe_lists')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('email_subscribe_subscribers')->cascadeOnDelete();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'subscriber_id']);
        });

        Schema::create('email_subscribe_sendouts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('entry_id')->nullable(); // Statamic entry powering the content
            $table->string('template')->nullable(); // view used to render the email
            $table->longText('html')->nullable(); // compiled snapshot at send time
            $table->json('list_ids');
            $table->string('status')->default('draft'); // draft, pending, sending, paused, sent, cancelled, failed
            $table->timestamp('send_at')->nullable(); // scheduled sendouts
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('email_subscribe_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sendout_id')->constrained('email_subscribe_sendouts')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->timestamps();
        });

        Schema::create('email_subscribe_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sendout_id')->nullable()->constrained('email_subscribe_sendouts')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('email_subscribe_subscribers')->cascadeOnDelete();
            $table->string('type'); // sent, open, click, bounce, complaint, unsubscribe
            $table->string('url', 2048)->nullable(); // clicked link
            $table->string('detail')->nullable(); // bounce reason etc.
            $table->timestamps();

            $table->index(['sendout_id', 'type']);
            $table->index(['subscriber_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_subscribe_events');
        Schema::dropIfExists('email_subscribe_links');
        Schema::dropIfExists('email_subscribe_sendouts');
        Schema::dropIfExists('email_subscribe_list_subscriber');
        Schema::dropIfExists('email_subscribe_subscribers');
        Schema::dropIfExists('email_subscribe_lists');
    }
};
