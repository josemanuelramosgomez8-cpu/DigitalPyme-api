<?php

namespace App\Http\Controllers;

use App\Models\AgendaReservation;
use App\Models\AgendaSlot;
use App\Support\AdminJsonPagination;
use App\Support\SolicitudNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgendaController extends Controller
{
    private const TIMES = ['09:00', '10:30', '15:00', '16:30'];

    public function slots(Request $request)
    {
        $datos = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $datos['month']);
        $firstDay = $month->startOfMonth();
        $lastDay = $month->endOfMonth();

        abort_if(
            $firstDay->lt(now()->startOfMonth()) || $firstDay->gt(now()->addMonths(6)->startOfMonth()),
            422,
            'La agenda solo permite consultar los próximos seis meses.'
        );

        $this->ensureDefaultSlots($firstDay, $lastDay);

        $slots = AgendaSlot::query()
            ->whereBetween('date', [$firstDay->toDateString(), $lastDay->toDateString()])
            ->where('active', true)
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->orderBy('time')
            ->get(['id', 'date', 'time', 'available'])
            ->map(fn (AgendaSlot $slot) => [
                'id' => $slot->id,
                'date' => $slot->date->format('Y-m-d'),
                'time' => substr($slot->time, 0, 5),
                'available' => $slot->available,
                'low' => $slot->available > 0 && $slot->available <= 2,
            ]);

        return response()->json(['month' => $datos['month'], 'slots' => $slots]);
    }

    public function reserve(Request $request, SolicitudNotifier $notifier)
    {
        $datos = $request->validate([
            'agenda_slot_id' => 'required|integer|exists:agenda_slots,id',
            'nombre' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|required_without:telefono',
            'telefono' => 'nullable|string|max:30|required_without:email',
        ]);

        $reserva = DB::transaction(function () use ($datos) {
            $slot = AgendaSlot::query()
                ->whereKey($datos['agenda_slot_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $slot->active || $slot->available < 1 || $slot->date->isBefore(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'agenda_slot_id' => 'Este horario ya no está disponible. Elige otro horario.',
                ]);
            }

            $slot->decrement('available');

            return AgendaReservation::create([
                'agenda_slot_id' => $slot->id,
                'nombre' => $datos['nombre'],
                'email' => $datos['email'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
            ]);
        });
        $reserva->load('slot');
        $notificacion = $notifier->notifyReservation($reserva);

        return response()->json([
            'mensaje' => 'El horario quedó reservado correctamente.',
            'reserva' => $reserva->load('slot:id,date,time'),
            ...$notificacion,
        ], 201);
    }

    public function adminSlots(Request $request)
    {
        $datos = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $datos['month']);

        return response()->json(
            AgendaSlot::whereBetween('date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ])->orderBy('date')->orderBy('time')->get()
        );
    }

    public function storeSlot(Request $request)
    {
        $datos = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'time' => ['required', 'date_format:H:i', Rule::in(self::TIMES)],
            'capacity' => 'sometimes|required|integer|min:1|max:100',
            'active' => 'sometimes|boolean',
        ]);
        $capacity = $datos['capacity'] ?? 1;

        $slot = AgendaSlot::firstOrNew([
            'date' => $datos['date'],
            'time' => $datos['time'],
        ]);

        if ($slot->exists) {
            $reservadas = $slot->capacity - $slot->available;
            if ($capacity < $reservadas) {
                throw ValidationException::withMessages([
                    'capacity' => 'La capacidad no puede ser menor al número de reservas existentes.',
                ]);
            }
            $slot->available += $capacity - $slot->capacity;
        } else {
            $slot->available = $capacity;
        }

        $slot->capacity = $capacity;
        $slot->active = $datos['active'] ?? true;
        $slot->save();

        return response()->json($slot, $slot->wasRecentlyCreated ? 201 : 200);
    }

    public function adminReservations(Request $request)
    {
        $datos = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $datos['month']);

        $reservations = AdminJsonPagination::paginate(
            AgendaReservation::with('slot:id,date,time')
                ->where('estado', 'reservada')
                ->whereHas('slot', fn ($query) => $query->whereBetween('date', [
                    $month->startOfMonth()->toDateString(),
                    $month->endOfMonth()->toDateString(),
                ]))
                ->orderByDesc('id'),
            $request,
        );

        return AdminJsonPagination::respond($reservations);
    }

    public function cancelReservation(AgendaReservation $reservation)
    {
        DB::transaction(function () use ($reservation) {
            $reservation = AgendaReservation::whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->estado !== 'reservada') {
                throw ValidationException::withMessages([
                    'reservation' => 'La reserva ya fue cancelada.',
                ]);
            }

            $slot = AgendaSlot::whereKey($reservation->agenda_slot_id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservation->update(['estado' => 'cancelada']);
            $slot->increment('available');
        });

        return response()->json(['mensaje' => 'La reserva fue cancelada y el cupo quedó disponible nuevamente.']);
    }

    private function ensureDefaultSlots(CarbonImmutable $firstDay, CarbonImmutable $lastDay): void
    {
        $today = CarbonImmutable::today();
        $defaults = [];

        for ($date = $firstDay->max($today); $date->lte($lastDay); $date = $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            foreach (self::TIMES as $time) {
                $defaults[] = [
                    'date' => $date->toDateString(),
                    'time' => $time,
                    'capacity' => 1,
                    'available' => 1,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($defaults !== []) {
            AgendaSlot::upsert($defaults, ['date', 'time'], ['updated_at']);
        }
    }
}
