<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\ConfirmedOrderNotifications;
use App\Domain\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

final class ConfirmedOrderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_order_notifies_only_authorized_accounts_and_never_duplicates(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $seller = $scenario->item['product']->seller_id;
        $branch = $scenario->item['branch']->id;

        $sellerOwner = User::factory()->create();
        $sellerStaff = User::factory()->create();
        $unrelatedSellerUser = User::factory()->create();
        $admin = User::factory()->create();
        $adminWithoutOrders = User::factory()->create();
        $unrelatedAdmin = User::factory()->create();
        $superAdmin = User::factory()->create();
        $inactiveSuperAdmin = User::factory()->create();
        $unrelatedSeller = Seller::factory()->create();

        foreach ([
            [$sellerOwner, $seller, null, true],
            [$sellerStaff, $seller, $branch, true],
            [$unrelatedSellerUser, $unrelatedSeller->id, null, true],
        ] as [$person, $sellerId, $branchId, $active]) {
            DB::table('seller_staff_access')->insert([
                'user_id' => $person->id, 'seller_id' => $sellerId, 'branch_id' => $branchId,
                'role' => $branchId === null ? 'owner' : 'staff',
                'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            [$admin, $seller, true],
            [$adminWithoutOrders, $seller, false],
            [$unrelatedAdmin, $unrelatedSeller->id, true],
        ] as [$person, $sellerId, $canView]) {
            DB::table('platform_admin_access')->insert([
                'user_id' => $person->id, 'seller_id' => $sellerId,
                'can_view_orders' => $canView, 'can_view_inventory' => true,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([[$superAdmin, true], [$inactiveSuperAdmin, false]] as [$person, $active]) {
            DB::table('platform_super_admin_grants')->insert([
                'user_id' => $person->id, 'is_active' => $active,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $orderId = $scenario->checkout(method: 'cash_on_delivery')->assertCreated()->json('data.order.id');
        $order = Order::query()->findOrFail($orderId);
        $writer = app(ConfirmedOrderNotifications::class);
        $writer->send($order);
        $writer->send($order); // A retry must not create a duplicate.

        foreach ([
            [$scenario->user, 'order_confirmed'],
            [$sellerOwner, 'seller_new_order'],
            [$sellerStaff, 'seller_new_order'],
            [$admin, 'admin_new_order'],
            [$superAdmin, 'super_admin_new_order'],
        ] as [$person, $type]) {
            $this->assertSame(1, DB::table('customer_notifications')
                ->where('user_id', $person->id)->where('type', $type)->count());
        }

        foreach ([$unrelatedSellerUser, $adminWithoutOrders, $unrelatedAdmin, $inactiveSuperAdmin] as $person) {
            $this->assertSame(0, DB::table('customer_notifications')
                ->where('user_id', $person->id)->count());
        }

        Sanctum::actingAs($sellerOwner, ['seller']);
        $this->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'seller_new_order');
    }

    public function test_unconfirmed_order_does_not_notify_sellers_or_admins(): void
    {
        $scenario = (new CheckoutScenario($this))->withCart();
        $orderId = $scenario->checkout()->assertCreated()->json('data.order.id');
        app(ConfirmedOrderNotifications::class)->send(Order::query()->findOrFail($orderId));

        $this->assertSame(0, DB::table('customer_notifications')
            ->whereIn('type', ['seller_new_order', 'admin_new_order', 'super_admin_new_order', 'order_confirmed'])
            ->count());
    }
}
