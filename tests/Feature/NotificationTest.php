<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_read_and_clear_only_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = UserNotification::create(['user_id' => $user->id, 'title' => 'First']);
        UserNotification::create(['user_id' => $user->id, 'title' => 'Second']);
        $theirs = UserNotification::create(['user_id' => $other->id, 'title' => 'Not yours']);
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('unread', 2)
            ->assertJsonPath('data.0.title', 'Second');

        $this->postJson("/api/notifications/{$first->id}/read")->assertOk()->assertJsonPath('read_at', fn ($at) => $at !== null);
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('unread', 1);

        $this->postJson("/api/notifications/{$theirs->id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$theirs->id}")->assertNotFound();

        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('unread', 0);
        $this->assertNull($theirs->fresh()->read_at);

        $this->deleteJson("/api/notifications/{$first->id}")->assertNoContent();
        $this->getJson('/api/notifications')->assertJsonPath('total', 1);
    }
}
