<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            // Orders use ULIDs; the older numeric source_order_id cannot safely identify them.
            $table->string('event_key', 120)->nullable()->after('source_order_id');
            $table->unique(['user_id', 'event_key'], 'customer_notifications_user_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->dropUnique('customer_notifications_user_event_unique');
            $table->dropColumn('event_key');
        });
    }
};
