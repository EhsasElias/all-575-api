<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Deployments that ran the original notification migration before source_order_id
        // was added need the same deduplication guarantee as fresh installations.
        if (! Schema::hasTable('customer_notifications')) {
            return;
        }

        if (! Schema::hasColumn('customer_notifications', 'source_order_id')) {
            Schema::table('customer_notifications', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_order_id')->nullable();
                $table->unique(['user_id', 'type', 'source_order_id'], 'customer_notification_order_unique');
            });
        }
    }

    public function down(): void
    {
        // Never drop the column on rollback: the original migration owns it on new installs.
    }
};
