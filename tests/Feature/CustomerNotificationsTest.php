<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\CustomerNotificationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_notifications(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->patchJson('/api/v1/me/notifications/read-all')->assertUnauthorized();
    }

    public function test_notifications_are_private_and_can_be_marked_read(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = DB::table('customer_notifications')->insertGetId([
            'user_id' => $owner->id,
            'type' => 'support_reply',
            'title' => 'Reply',
            'message' => 'Your request was updated.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsCustomer($other);
        $this->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->patchJson('/api/v1/me/notifications/'.$id.'/read')->assertNotFound();
        $this->patchJson('/api/v1/me/notifications/read-all')->assertOk();
        $this->assertDatabaseHas('customer_notifications', ['id' => $id, 'read_at' => null]);

        $this->actingAsCustomer($owner);
        $this->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1);
        $this->patchJson('/api/v1/me/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/me/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_order_notifications_are_deduplicated(): void
    {
        $user = User::factory()->create();
        $writer = app(CustomerNotificationWriter::class);
        $writer->orderPlaced($user->id, 123);
        $writer->orderPlaced($user->id, 123);
        $this->assertDatabaseCount('customer_notifications', 1);
    }

    public function test_mark_all_only_updates_current_customer_and_is_repeatable(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$owner, $other] as $customer) {
            DB::table('customer_notifications')->insert([
                'user_id' => $customer->id,
                'type' => 'test',
                'title' => 'Test',
                'message' => 'Notice',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAsCustomer($owner);
        $this->patchJson('/api/v1/me/notifications/read-all')->assertOk();
        $this->patchJson('/api/v1/me/notifications/read-all')->assertOk();

        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertSame(0, DB::table('customer_notifications')->where('user_id', $owner->id)->whereNull('read_at')->count());
        $this->assertSame(1, DB::table('customer_notifications')->where('user_id', $other->id)->whereNull('read_at')->count());
    }

    public function test_cursor_and_page_size_are_bounded(): void
    {
        $user = $this->actingAsCustomer();
        foreach (range(1, 5) as $number) {
            DB::table('customer_notifications')->insert([
                'user_id' => $user->id,
                'type' => 'test',
                'title' => 'Notice '.$number,
                'message' => 'Example',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/v1/me/notifications?limit=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('unread_count', 5);
        $this->getJson('/api/v1/me/notifications?limit=2&before_id=3')->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_known_order_notifications_are_rendered_in_requested_language_without_changing_storage(): void
    {
        $user = $this->actingAsCustomer();
        DB::table('customer_notifications')->insert([
            'user_id' => $user->id,
            'type' => 'order_confirmed',
            'title' => 'تم تأكيد طلبك',
            'message' => 'تم تأكيد الطلب 575-123 ويمكنك متابعة حالته من صفحة طلباتي.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Your order is confirmed')
            ->assertJsonPath('data.0.message', 'Order 575-123 has been confirmed. You can track it from My Orders.');

        $this->withHeaders(['Accept-Language' => 'fr'])->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Votre commande est confirmée')
            ->assertJsonPath('data.0.message', 'La commande 575-123 est confirmée. Vous pouvez la suivre dans Mes commandes.');

        $this->withHeaders(['Accept-Language' => 'ar'])->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'تم تأكيد طلبك');

        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => $user->id,
            'type' => 'order_confirmed',
            'title' => 'تم تأكيد طلبك',
        ]);
    }

    public function test_unknown_notification_type_keeps_its_original_content(): void
    {
        $user = $this->actingAsCustomer();
        DB::table('customer_notifications')->insert([
            'user_id' => $user->id,
            'type' => 'support_reply',
            'title' => 'رد الدعم',
            'message' => 'نص مخصص من الدعم',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'رد الدعم')
            ->assertJsonPath('data.0.message', 'نص مخصص من الدعم');
    }
}
