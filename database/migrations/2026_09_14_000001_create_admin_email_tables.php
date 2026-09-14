<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_email_threads')) {
            Schema::create('admin_email_threads', function (Blueprint $table) {
                $table->id();
                $table->string('mailbox_provider_id', 64)->nullable()->index();
                $table->string('subject')->nullable();
                $table->string('subject_normalized')->nullable()->index();
                $table->json('participants')->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->string('last_direction', 10)->nullable();
                $table->string('status', 20)->default('open')->index();
                $table->unsignedInteger('message_count')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_message_at']);
            });
        }

        if (! Schema::hasTable('admin_email_messages')) {
            Schema::create('admin_email_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('thread_id')
                    ->constrained('admin_email_threads')
                    ->cascadeOnDelete();
                $table->string('direction', 10);
                $table->string('from_email')->nullable();
                $table->string('from_name')->nullable();
                $table->json('to_emails')->nullable();
                $table->json('cc_emails')->nullable();
                $table->string('subject')->nullable();
                $table->longText('body_text')->nullable();
                $table->longText('body_html')->nullable();
                $table->string('message_id')->nullable()->unique();
                $table->string('in_reply_to')->nullable()->index();
                $table->text('references_header')->nullable();
                $table->unsignedBigInteger('imap_uid')->nullable();
                $table->string('imap_folder', 120)->nullable();
                $table->string('provider_id', 64)->nullable();
                $table->foreignId('sent_by_admin_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('status', 20)->default('received');
                $table->text('error')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['thread_id', 'created_at']);
                $table->unique(['provider_id', 'imap_folder', 'imap_uid'], 'admin_email_messages_imap_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_email_messages');
        Schema::dropIfExists('admin_email_threads');
    }
};
