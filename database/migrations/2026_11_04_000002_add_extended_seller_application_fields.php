<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = [
        'entity_type' => 'string',
        'commercial_registration_status' => 'string',
        'commercial_registration_issue_date' => 'date',
        'commercial_registration_expiry_date' => 'date',
        'chamber_of_commerce_number' => 'string',
        'building_number' => 'string',
        'postal_code' => 'string',
        'additional_number' => 'string',
        'street' => 'string',
        'district' => 'string',
        'iban' => 'string',
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $column => $type) {
            if (Schema::hasColumn('seller_applications', $column)) {
                continue;
            }
            Schema::table('seller_applications', static function (Blueprint $table) use ($column, $type): void {
                if ($type === 'date') {
                    $table->date($column)->nullable();
                } else {
                    $table->string($column, $column === 'street' ? 180 : 100)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FIELDS) as $column) {
            if (Schema::hasColumn('seller_applications', $column)) {
                Schema::table('seller_applications', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
