<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationTranslation;
use App\Domain\Notifications\OrderProgressNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderProgressNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_notice_is_scoped_and_deduplicated(): void
    {
        $seller = Seller::factory()->create();
        $branch = Branch::factory()->for($seller)->create();
        $buyer = User::factory()->create();
        $courier = User::factory()->create();
        $staff = User::factory()->create();
        $admin = User::factory()->create();
        $unrelated = User::factory()->create();

        DB::table('seller_staff_access')->insert([
            'user_id' => $staff->id,
            'seller_id' => $seller->id,
            'branch_id' => $branch->id,
            'role' => 'branch_manager',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('platform_admin_access')->insert([
            'user_id' => $admin->id,
            'seller_id' => $seller->id,
            'is_active' => true,
            'can_view_orders' => true,
            'can_view_inventory' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = (object) ['user_id' => $buyer->id, 'number' => '575-123'];
        $suborder = (object) [
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'seller_id' => $seller->id,
            'branch_id' => $branch->id,
        ];
        $writer = app(OrderProgressNotifier::class);
        $writer->record($order, $suborder, 'courier_picked_up', $courier->id);
        $writer->record($order, $suborder, 'courier_picked_up', $courier->id);

        foreach ([$buyer, $courier, $staff, $admin] as $recipient) {
            $this->assertSame(1, DB::table('customer_notifications')
                ->where('user_id', $recipient->id)->count());
        }
        $this->assertSame(0, DB::table('customer_notifications')
            ->where('user_id', $unrelated->id)->count());
        $this->assertSame(4, DB::table('customer_notifications')->count());
    }

    public function test_delivery_events_translate_to_english_and_french_without_exposing_codes(): void
    {
        $arabic = NotificationTranslation::render(
            'courier_delivery_failed', 'تعذر التسليم',
            'تعذرت محاولة تسليم الطلب 575-123 وتم تسجيل بلاغ للمتابعة.', 'ar'
        );
        $english = NotificationTranslation::render(
            'courier_delivery_failed', $arabic['title'], $arabic['message'], 'en'
        );
        $french = NotificationTranslation::render(
            'courier_delivery_failed', $arabic['title'], $arabic['message'], 'fr'
        );

        $this->assertSame('تعذر التسليم', $arabic['title']);
        $this->assertSame('Delivery attempt failed', $english['title']);
        $this->assertSame('Échec de livraison', $french['title']);
        $this->assertStringContainsString('575-123', $french['message']);
    }
}
