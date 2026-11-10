<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Cotizacion;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectMonthlyCapacity;
use App\Models\User;
use App\Support\CotizacionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CotizacionController extends Controller
{
    public const ESTADOS = ['borrador', 'enviada', 'aceptada', 'rechazada'];

    public function index(Lead $lead)
    {
        return response()->json($lead->cotizaciones()->latest()->get());
    }

    public function store(Request $request, Lead $lead)
    {
        $datos = $request->validate([
            'servicio' => 'required|string|max:255',
            'descripcion' => 'required|string|max:5000',
            'monto' => 'required|numeric|min:0|max:99999999',
        ]);
        $datos['estado'] = 'borrador';

        $cotizacion = DB::transaction(function () use ($lead, $datos) {
            $cotizacion = $lead->cotizaciones()->create($datos);

            if (in_array($lead->estado, ['nuevo', 'contactado'], true)) {
                $lead->update(['estado' => 'contactado']);
            }

            return $cotizacion;
        });

        return response()->json($cotizacion, 201);
    }

    public function update(Request $request, Cotizacion $cotizacion, CotizacionNotifier $notifier)
    {
        $datos = $request->validate([
            'servicio' => 'sometimes|required|string|max:255',
            'descripcion' => 'sometimes|nullable|string|max:5000',
            'monto' => 'sometimes|required|numeric|min:0|max:99999999',
            'estado' => ['sometimes', 'required', Rule::in(self::ESTADOS)],
        ]);
        if ($datos === []) {
            throw ValidationException::withMessages([
                'cotizacion' => 'Incluye al menos un campo para actualizar la cotización.',
            ]);
        }
        $estado = $datos['estado'] ?? $cotizacion->estado;

        [$cotizacion, $shouldSendEmail] = DB::transaction(function () use ($cotizacion, $datos, $estado) {
            $cotizacion = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $lead = $cotizacion->lead()->lockForUpdate()->firstOrFail();
            $shouldSendEmail = $estado === 'enviada' && $cotizacion->estado !== 'enviada';
            $monto = $datos['monto'] ?? $cotizacion->monto;
            $descripcion = $datos['descripcion'] ?? $cotizacion->descripcion;

            if (
                $cotizacion->estado !== 'borrador'
                && array_intersect(['servicio', 'descripcion', 'monto'], array_keys($datos)) !== []
            ) {
                throw ValidationException::withMessages([
                    'cotizacion' => 'Solo se puede editar el precio y alcance mientras la cotización está en borrador.',
                ]);
            }

            if (in_array($estado, ['enviada', 'aceptada'], true) && (float) $monto <= 0) {
                throw ValidationException::withMessages([
                    'monto' => 'Confirma un monto mayor que cero antes de enviar o aceptar la cotización.',
                ]);
            }

            if (in_array($estado, ['enviada', 'aceptada'], true) && blank($descripcion)) {
                throw ValidationException::withMessages([
                    'descripcion' => 'Define el alcance del proyecto antes de enviar o aceptar la cotización.',
                ]);
            }

            if ($estado === 'enviada' && blank($lead->email)) {
                throw ValidationException::withMessages([
                    'lead' => 'Agrega un correo electrónico al cliente antes de enviar la cotización.',
                ]);
            }

            $cotizacion->update($datos);

            $tieneAceptada = $lead->cotizaciones()
                ->where('estado', 'aceptada')
                ->exists();

            if ($estado === 'aceptada') {
                $client = $this->ensureClientForLead($lead);

                $month = now()->startOfMonth()->toDateString();
                $capacity = ProjectMonthlyCapacity::firstOrCreate(
                    ['month' => $month],
                    ['capacity' => 10, 'reserved' => 0],
                );
                $capacity = ProjectMonthlyCapacity::whereKey($capacity->id)->lockForUpdate()->firstOrFail();
                $projectExists = Project::where('cotizacion_id', $cotizacion->id)->exists();

                if (! $projectExists && $capacity->reserved >= $capacity->capacity) {
                    throw ValidationException::withMessages([
                        'capacidad' => 'No quedan cupos de proyectos disponibles para este mes.',
                    ]);
                }

                $project = Project::firstOrCreate(
                    ['cotizacion_id' => $cotizacion->id],
                    [
                        'client_id' => $client->id,
                        'nombre' => $cotizacion->servicio,
                        'descripcion' => $cotizacion->descripcion,
                        'estado' => 'planificacion',
                        'progreso' => 0,
                    ]
                );

                if ($project->wasRecentlyCreated) {
                    $capacity->increment('reserved');
                }
            }

            if ($tieneAceptada) {
                $lead->update(['estado' => 'ganado']);
            } elseif ($estado === 'enviada') {
                $lead->update(['estado' => 'cotizado']);
            } elseif ($lead->estado === 'ganado') {
                $lead->update(['estado' => 'cotizado']);
            }

            return [$cotizacion, $shouldSendEmail];
        });

        $emailEnviado = null;
        if ($shouldSendEmail) {
            $emailEnviado = $notifier->send($cotizacion->refresh());
        }

        return response()->json([
            ...$cotizacion->refresh()->toArray(),
            ...($emailEnviado === null ? [] : ['email_enviado' => $emailEnviado]),
        ]);
    }

    public function resend(Cotizacion $cotizacion, CotizacionNotifier $notifier)
    {
        if ($cotizacion->estado !== 'enviada') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se pueden reenviar cotizaciones que ya fueron enviadas.',
            ]);
        }

        return response()->json([
            ...$cotizacion->toArray(),
            'email_enviado' => $notifier->send($cotizacion),
        ]);
    }

    private function ensureClientForLead(Lead $lead): Client
    {
        $client = $lead->client;

        if ($client) {
            return $client;
        }

        if (blank($lead->email)) {
            throw ValidationException::withMessages([
                'lead' => 'El lead necesita un correo electrónico para crear el acceso del cliente antes de aceptar la cotización.',
            ]);
        }

        $user = User::where('email', $lead->email)->first();

        if ($user) {
            if (! $user->is_client || $user->client) {
                throw ValidationException::withMessages([
                    'lead' => 'El correo del lead ya pertenece a otra cuenta. Convierte el lead en cliente con otro correo antes de aceptar.',
                ]);
            }
        } else {
            $user = new User;
            $user->name = $lead->nombre;
            $user->email = $lead->email;
            $user->password = Hash::make(Str::random(64));
            $user->is_client = true;
            $user->save();
        }

        return Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
            'empresa' => $lead->empresa,
        ]);
    }
}
