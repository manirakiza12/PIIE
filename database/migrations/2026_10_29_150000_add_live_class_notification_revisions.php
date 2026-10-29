<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('live_classes', function (Blueprint $table): void {
            $table->unsignedInteger('schedule_revision')->default(0);
            $table->unsignedInteger('notification_revision')->default(0);
        });
        Schema::create('live_class_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('live_class_id');
            $table->unsignedBigInteger('user_id');
            $table->string('event_key', 64);
            $table->unsignedInteger('schedule_revision')->default(0);
            $table->unsignedInteger('notification_revision')->default(0);
            $table->text('encrypted_message');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();
            $table->unique(['live_class_id', 'user_id', 'event_key'], 'live_class_email_event_unique');
            $table->index(['sent_at', 'discarded_at', 'available_at'], 'live_class_email_pending');
        });
    }

    public function down(): void
    {
        // Keep notification history and its revision identity on code rollback.
    }
};
