<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings › Change history can undo an entry: when and by whom it was
 * undone, and on the entry the undo itself wrote, which entry it reverted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('undo_of')->nullable()->constrained('audit_logs')->nullOnDelete();
            $table->index(['workspace_id', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'timestamp']);
            $table->dropConstrainedForeignId('undo_of');
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn('undone_at');
        });
    }
};
