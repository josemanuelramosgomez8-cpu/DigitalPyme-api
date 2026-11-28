<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCotizacionRequest;
use App\Models\Client;
use App\Models\Cotizacion;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectMonthlyCapacity;
use App\Models\Servicio;
use App\Models\User;
use App\Services\CustomerLifecycleNotifier;
use App\Support\CotizacionNotifier;
use App\Support\SolicitudNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CotizacionController extends Controller
{
    public const ESTADOS = ['borrador', 'enviada', 'aceptada', 'rechazada'];

    public function storePublic(StoreCotizacionRequest $request, SolicitudNotifier $notifier)
    {
        $datos = $request->validated();
        $servicioIds = $datos['servicios_ids'] ?? [$datos['servicio_id']];
        $servicios = Servicio::query()
            ->where('activo', true)
            ->whereIn('id', $servicioIds)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'nombre', 'precio_desde']);

        $montoCentimos = $servicios->sum(
            fn (Servicio $servicio) => $servicio->precio_desde === null
                ? 0
                : (int) round((float) $servicio->precio_desde * 100)
        );
        $inversionEstimada = $servicios->contains(
            fn (Servicio $servicio) => $servicio->precio_desde !== null
        ) ? $montoCentimos / 100 : null;
        $contacto = trim($datos['contacto']);
        $esCorreo = filter_var($contacto, FILTER_VALIDATE_EMAIL) !== false;
        $lead = Lead::create([
            'nombre' => trim($datos['nombre']),
            'empresa' => $datos['empresa'] ?? null,
            'email' => $esCorreo ? $contacto : null,
            'telefono' => $esCorreo ? null : $contacto,
            'servicio' => $servicios->pluck('nombre')->implode(', '),
            'mensaje' => $datos['mensaje'] ?? null,
            'inversion_estimada' => $inversionEstimada,
        ]);
        $notificacion = $notifier->notify($lead);

        return response()->json([
            'success' => true,
            'message' => 'La solicitud de cotización fue recibida correctamente.',
            'data' => [
                'id' => $lead->id,
                'inversion_estimada' => $lead->inversion_estimada,
            ],
            ...$notificacion,
        ], 201);
    }

    public function index(Lead $lead)
    {
        return response()->json($lead->cotizaciones()->latest()->get());
    }

    public function clientIndex(Request $request)
    {
        $client = $request->user()->client;
        abort_unless($client, 404);

        $quotes = Cotizacion::query()
            ->with('lead:id,nombre,email')
            ->whereIn('estado', ['enviada', 'aceptada', 'rechazada'])
            ->whereHas('lead', function ($query) use ($client, $request) {
                $query->where(function ($query) use ($client, $request) {
                    $query->where('email', $request->user()->email);
                    if ($client->lead_id !== null) {
                        $query->orWhere('id', $client->lead_id);
                    }
                })->where(function ($query) use ($request) {
                    $query->whereDoesntHave('client')
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                            ->where('user_id', $request->user()->id));
                });
            })
            ->latest()
            ->get();

        return response()->json($quotes);
    }

    public function clientRespond(Request $request, Cotizacion $cotizacion)
    {
        $datos = $request->validate([
            'respuesta' => ['required', Rule::in(['aceptada', 'rechazada'])],
        ]);

        [$cotizacion, $newlyAccepted] = DB::transaction(function () use ($request, $cotizacion, $datos) {
            $cotizacion = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $lead = Lead::whereKey($cotizacion->lead_id)->lockForUpdate()->firstOrFail();
            $client = $request->user()->client;

            if (! $client) {
                abort(404);
            }

            $ownsLead = $client->lead_id === $lead->id
                || (
                    filled($lead->email)
                    && strcasecmp($lead->email, $request->user()->email) === 0
                );
            if (! $ownsLead) {
                abort(404);
            }

            if ($lead->client && $lead->client->user_id !== $request->user()->id) {
                abort(404);
            }

            if ($cotizacion->estado !== 'enviada') {
                throw ValidationException::withMessages([
                    'cotizacion' => 'Solo puedes responder cotizaciones que estén enviadas y aún no hayan sido respondidas.',
                ]);
            }

            if ($datos['respuesta'] === 'aceptada') {
                if ((float) $cotizacion->monto <= 0 || blank($cotizacion->descripcion)) {
                    throw ValidationException::withMessages([
                        'cotizacion' => 'La cotización debe tener un monto y alcance válidos antes de aceptarse.',
                    ]);
                }

                $clientForProject = $this->ensureClientForLead($lead);
                if ($clientForProject->user_id !== $request->user()->id) {
                    abort(404);
                }
                $this->createProjectForAcceptedQuote($cotizacion, $clientForProject);
                $lead->update(['estado' => 'ganado']);
            }

            $cotizacion->update([
                'estado' => $datos['respuesta'],
                'responded_at' => now(),
            ]);

            return [$cotizacion->refresh(), $datos['respuesta'] === 'aceptada'];
        });

        if ($newlyAccepted) {
            $this->notifyAcceptedQuote($cotizacion);
        }

        return response()->json($cotizacion->load('lead:id,nombre,email'));
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

        [$cotizacion, $shouldSendEmail, $newlyAccepted] = DB::transaction(function () use ($cotizacion, $datos, $estado) {
            $cotizacion = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $lead = $cotizacion->lead()->lockForUpdate()->firstOrFail();
            $shouldSendEmail = $estado === 'enviada' && $cotizacion->estado !== 'enviada';
            $newlyAccepted = $estado === 'aceptada' && $cotizacion->estado !== 'aceptada';
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
                $this->createProjectForAcceptedQuote($cotizacion, $client);
            }

            if ($tieneAceptada) {
                $lead->update(['estado' => 'ganado']);
            } elseif ($estado === 'enviada') {
                $lead->update(['estado' => 'cotizado']);
            } elseif ($lead->estado === 'ganado') {
                $lead->update(['estado' => 'cotizado']);
            }

            return [$cotizacion, $shouldSendEmail, $newlyAccepted];
        });

        $emailEnviado = null;
        if ($shouldSendEmail) {
            $emailEnviado = $notifier->send($cotizacion->refresh());
        }

        if ($newlyAccepted) {
            $this->notifyAcceptedQuote($cotizacion);
        }

        $emailMessage = $emailEnviado === null
            ? []
            : [
                'email_enviado' => $emailEnviado,
                'mensaje' => $emailEnviado
                    ? 'Cotización enviada correctamente al correo del cliente.'
                    : 'La cotización quedó marcada como enviada, pero no se pudo entregar el correo. Configura y verifica el servicio SMTP antes de informar al cliente.',
            ];

        return response()->json([
            ...$cotizacion->refresh()->toArray(),
            ...$emailMessage,
        ]);
    }

    public function resend(Cotizacion $cotizacion, CotizacionNotifier $notifier)
    {
        if ($cotizacion->estado !== 'enviada') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se pueden reenviar cotizaciones que ya fueron enviadas.',
            ]);
        }

        $emailEnviado = $notifier->send($cotizacion);

        return response()->json([
            ...$cotizacion->toArray(),
            'email_enviado' => $emailEnviado,
            'mensaje' => $emailEnviado
                ? 'Cotización reenviada correctamente al correo del cliente.'
                : 'No se pudo reenviar la cotización. Verifica la configuración SMTP y vuelve a intentarlo.',
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
            if (! $user->is_client) {
                throw ValidationException::withMessages([
                    'lead' => 'El correo del lead ya pertenece a otra cuenta. Convierte el lead en cliente con otro correo antes de aceptar.',
                ]);
            }

            $client = $user->client;
            if ($client) {
                if ($client->lead_id === null) {
                    $client->update(['lead_id' => $lead->id]);
                }

                return $client;
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

    private function createProjectForAcceptedQuote(Cotizacion $cotizacion, Client $client): Project
    {
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

        return $project;
    }

    private function notifyAcceptedQuote(Cotizacion $cotizacion): void
    {
        $project = Project::query()
            ->with('client.user')
            ->where('cotizacion_id', $cotizacion->id)
            ->firstOrFail();
        $user = $project->client?->user;
        if (! $user) {
            return;
        }

        app(CustomerLifecycleNotifier::class)->notify(
            eventType: 'project_onboarding',
            email: $user->email,
            subject: 'Comencemos tu proyecto con DigitalPyme',
            intro: 'Tu propuesta fue aceptada. Completa el brief para que el equipo pueda iniciar el trabajo.',
            details: [
                'Proyecto: '.$project->nombre,
                'El enlace seguro del formulario vence en 30 días.',
            ],
            userId: $user->id,
            actionLabel: 'Completar mi brief',
            actionUrl: URL::temporarySignedRoute(
                'project-brief.show',
                now()->addDays(30),
                ['project' => $project->id],
            ),
        );
    }
}
