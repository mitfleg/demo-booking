<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $otherUser;
    private Room $moscowRoom;
    private Room $novosibirskRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->moscowRoom = Room::factory()->create();
        $this->novosibirskRoom = Room::factory()
            ->for(Office::factory()->novosibirsk())
            ->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'room_id' => $this->moscowRoom->id,
            'title' => 'Планёрка',
            'starts_at' => '2030-02-05T10:00:00+03:00',
            'ends_at' => '2030-02-05T11:00:00+03:00',
        ], $overrides);
    }

    private function book(array $overrides = []): TestResponse
    {
        Sanctum::actingAs($this->author);

        return $this->postJson('/api/bookings', $this->payload($overrides));
    }

    public function test_creates_booking_with_utc_dates_and_authenticated_author(): void
    {
        $this->book(['user_id' => $this->otherUser->id])
            ->assertCreated()
            ->assertJsonPath('user_id', $this->author->id)
            ->assertJsonPath('title', 'Планёрка');

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->author->id,
            'starts_at' => '2030-02-05 07:00:00',
            'ends_at' => '2030-02-05 08:00:00',
        ]);
    }

    public function test_rejects_every_kind_of_overlap(): void
    {
        $this->book()->assertCreated();

        foreach ([
            ['09:45', '10:15'],
            ['10:15', '10:45'],
            ['09:00', '12:00'],
            ['10:00', '11:00'],
        ] as [$from, $to]) {
            $this->book([
                'starts_at' => "2030-02-05T{$from}:00+03:00",
                'ends_at' => "2030-02-05T{$to}:00+03:00",
            ])->assertStatus(409);
        }

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_back_to_back_bookings(): void
    {
        $this->book()->assertCreated();
        $this->book([
            'starts_at' => '2030-02-05T11:00:00+03:00',
            'ends_at' => '2030-02-05T12:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_same_time_in_another_room_is_allowed(): void
    {
        $this->book()->assertCreated();
        $secondRoom = Room::factory()->create();
        $this->book(['room_id' => $secondRoom->id])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_allows_minimum_duration_and_exactly_four_hours(): void
    {
        $this->book([
            'starts_at' => '2030-02-05T09:00:00+03:00',
            'ends_at' => '2030-02-05T09:15:00+03:00',
        ])->assertCreated();

        $this->book([
            'starts_at' => '2030-02-05T16:00:00+03:00',
            'ends_at' => '2030-02-05T20:00:00+03:00',
        ])->assertCreated();
    }

    public function test_rejects_invalid_duration_and_off_grid_times(): void
    {
        foreach ([
            ['10:00:00', '10:14:00'],
            ['10:00:00', '14:15:00'],
            ['10:07:00', '11:07:00'],
            ['10:00:00', '10:00:00'],
            ['10:00:00', '09:45:00'],
            ['10:00:30', '11:00:30'],
        ] as [$from, $to]) {
            $this->book([
                'starts_at' => "2030-02-05T{$from}+03:00",
                'ends_at' => "2030-02-05T{$to}+03:00",
            ])->assertUnprocessable();
        }

        $this->book([
            'starts_at' => '2030-02-05T10:00:00.000001+03:00',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_enforces_entire_interval_within_office_hours(): void
    {
        foreach ([
            ['08:45', '09:00'],
            ['19:45', '20:15'],
            ['20:00', '20:15'],
        ] as [$from, $to]) {
            $this->book([
                'starts_at' => "2030-02-05T{$from}:00+03:00",
                'ends_at' => "2030-02-05T{$to}:00+03:00",
            ])->assertUnprocessable();
        }

        // Указанное клиентом 10:00 +07:00 означает 06:00 в Москве.
        $this->book([
            'starts_at' => '2030-02-05T10:00:00+07:00',
            'ends_at' => '2030-02-05T11:00:00+07:00',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_converts_arbitrary_offsets_to_office_local_time(): void
    {
        // 07:00 UTC = 10:00 в Москве.
        $this->book([
            'starts_at' => '2030-02-05T07:00:00Z',
            'ends_at' => '2030-02-05T08:00:00Z',
        ])->assertCreated();

        // 03:00 UTC = 10:00 в Новосибирске.
        $this->book([
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => '2030-02-05T03:00:00Z',
            'ends_at' => '2030-02-05T04:00:00Z',
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => '2030-02-05 03:00:00',
            'ends_at' => '2030-02-05 04:00:00',
        ]);
    }

    public function test_cannot_book_same_instant_using_another_offset(): void
    {
        $this->book()->assertCreated();
        $this->book([
            'starts_at' => '2030-02-05T07:00:00Z',
            'ends_at' => '2030-02-05T08:00:00Z',
        ])->assertStatus(409);
    }

    public function test_allows_past_and_far_future_dates(): void
    {
        $this->book([
            'starts_at' => '2000-01-01T09:00:00+03:00',
            'ends_at' => '2000-01-01T09:15:00+03:00',
        ])->assertCreated();

        $this->book([
            'starts_at' => '2050-12-31T19:45:00+03:00',
            'ends_at' => '2050-12-31T20:00:00+03:00',
        ])->assertCreated();
    }

    public function test_returns_422_with_field_errors_for_invalid_payloads(): void
    {
        $this->book(['room_id' => '1 OR 1=1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->book(['room_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->book(['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->book(['starts_at' => '2030-02-05 10:00:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->book(['starts_at' => '2030-02-30T10:00:00+03:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_only_owner_can_cancel_booking(): void
    {
        $booking = Booking::factory()
            ->for($this->author)
            ->for($this->moscowRoom)
            ->create();

        Sanctum::actingAs($this->otherUser);
        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);

        Sanctum::actingAs($this->author);
        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_deleting_missing_booking_returns_404(): void
    {
        Sanctum::actingAs($this->author);
        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_create_and_delete_require_authentication(): void
    {
        $booking = Booking::factory()->create();
        $this->postJson('/api/bookings', $this->payload())->assertUnauthorized();
        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();
    }
}
