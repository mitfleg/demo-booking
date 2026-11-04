<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $isoDateRule = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! self::isIso8601WithOffset($value)) {
                $fail('Укажите дату в формате ISO 8601 с часовым смещением, например 2030-02-05T10:00:00+03:00.');
            }
        };

        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', $isoDateRule],
            'ends_at' => ['required', 'string', $isoDateRule],
        ]);

        $startsAt = CarbonImmutable::parse($data['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($data['ends_at'])->utc();
        $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($durationSeconds < 15 * 60 || $durationSeconds > 4 * 60 * 60 || $durationSeconds % (15 * 60) !== 0) {
            throw ValidationException::withMessages([
                'ends_at' => 'Длительность бронирования должна составлять от 15 минут до 4 часов с шагом 15 минут.',
            ]);
        }

        $room = Room::with('office')->findOrFail($data['room_id']);
        $timezone = $room->office->timezone;
        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);

        foreach (['starts_at' => $localStart, 'ends_at' => $localEnd] as $field => $time) {
            if ($time->minute % 15 !== 0 || $time->second !== 0 || $time->microsecond !== 0) {
                throw ValidationException::withMessages([
                    $field => 'Время должно попадать на четверть часа: :00, :15, :30 или :45 (в часовом поясе офиса).',
                ]);
            }
        }

        if (
            $localStart->toDateString() !== $localEnd->toDateString()
            || $localStart->format('H:i:s') < '09:00:00'
            || $localEnd->format('H:i:s') > '20:00:00'
        ) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронирование должно целиком находиться в пределах 09:00–20:00 по местному времени офиса.',
            ]);
        }

        // Блокируем строку комнаты, а не существующие бронирования: даже когда их
        // ещё нет, два запроса одной комнаты будут выполняться последовательно.
        $booking = DB::transaction(function () use ($room, $request, $data, $startsAt, $endsAt): ?Booking {
            Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            $conflict = Booking::query()
                ->where('room_id', $room->id)
                ->where('starts_at', '<', $endsAt->format('Y-m-d H:i:s'))
                ->where('ends_at', '>', $startsAt->format('Y-m-d H:i:s'))
                ->exists();

            if ($conflict) {
                return null;
            }

            return Booking::create([
                'room_id' => $room->id,
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                // В базе timestamp без timezone: сохраняем только нормализованный UTC.
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            ]);
        });

        if ($booking === null) {
            return response()->json(['message' => 'Комната уже занята в выбранное время.'], 409);
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
        ]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Отменить бронирование может только его автор.'], 403);
        }

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }

    private static function isIso8601WithOffset(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)$/D',
            $value,
        )) {
            return false;
        }

        try {
            // Сверяем компоненты исходной даты, чтобы отсечь невозможные даты
            // вроде 2030-02-30, которые парсер мог бы нормализовать.
            return CarbonImmutable::parse($value)->format('Y-m-d\TH:i:s') === substr($value, 0, 19);
        } catch (\Throwable) {
            return false;
        }
    }
}
