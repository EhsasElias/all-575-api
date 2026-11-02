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
}
