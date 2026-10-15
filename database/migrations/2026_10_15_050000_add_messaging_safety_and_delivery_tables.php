<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable();
            $table->unique(['school_id', 'sender_id', 'client_message_id'], 'chat_submission_unique');
            $table->index(['school_id', 'message_thrade', 'id'], 'chat_thread_history');
            $table->index(['school_id', 'reciver_id', 'read_status'], 'chat_unread_recipient');
        });
        Schema::create('message_thread_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('thread_id');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'thread_id'], 'message_preference_owner');
        });
        Schema::create('messaging_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('blocked_user_id');
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'blocked_user_id'], 'message_block_owner');
        });
        Schema::create('messaging_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('reporter_id');
            $table->text('reason');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('messaging_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('last_chat_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'thread_id'], 'message_email_recipient');
        });
    }

    public function down(): void
    {
        // History and safety records are retained on code rollback.
        // Removing them requires a separately reviewed retention operation.
    }
};
