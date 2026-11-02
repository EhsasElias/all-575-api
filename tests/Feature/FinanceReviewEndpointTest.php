<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FinanceReviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_finance_approvals(): void
    {
        $this->getJson('/api/v1/finance/approvals')->assertUnauthorized();
        $this->getJson('/api/v1/finance/approvals/1/audit')->assertUnauthorized();
    }

    public function test_authenticated_user_without_finance_grant_cannot_read_approvals(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/finance/approvals')->assertForbidden();
        $this->getJson('/api/v1/finance/approvals/1/audit')->assertForbidden();
    }
}
