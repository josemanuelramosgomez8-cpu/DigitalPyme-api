<?php

namespace App\Http\Controllers;

use App\Mail\ClientRegisteredNotification;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class ClientController extends Controller
{
    public function profile(Request $request)
    {
        return response()->json($request->user()->client->only([
            'nombre',
            'empresa',
            'telefono',
            'tipo_documento',
            'numero_documento',
            'tipo_comprobante',
            'direccion_entrega',
            'ubicacion_entrega',
        ]));
    }

    public function register(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $client = DB::transaction(function () use ($datos) {
            $user = User::create([
                'name' => $datos['nombre'],
                'email' => $datos['email'],
                'password' => $datos['password'],
            ]);
            $user->is_client = true;
            $user->is_active = true;
            $user->save();

            return Client::create([
                'user_id' => $user->id,
                'nombre' => $datos['nombre'],
            ]);
        });

        $recipients = User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->pluck('email')
            ->all();

        if ($recipients === [] && filled(config('services.digitalpyme.solicitudes_email'))) {
            $recipients[] = config('services.digitalpyme.solicitudes_email');
        }

        $adminNotified = $recipients !== []
            && ! in_array(config('mail.default'), ['log', 'array'], true);

        foreach ($adminNotified ? array_unique($recipients) : [] as $recipient) {
            try {
                Mail::to($recipient)->send(new ClientRegisteredNotification($client));
            } catch (TransportExceptionInterface $exception) {
                $adminNotified = false;
                Log::warning('New client account created, but the admin notification email could not be delivered.', [
                    'client_id' => $client->id,
                    'recipient' => $recipient,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $user = $client->user;
        $token = $user->createToken('client')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => [
                'name' => $user->name,
                'email' => $user->email,
                'must_change_password' => false,
            ],
            'notificacion_admin_enviada' => $adminNotified,
        ], 201);
    }

    public function convertLead(Request $request, Lead $lead)
    {
        if ($lead->client()->exists()) {
            return response()->json(['mensaje' => 'Este lead ya está asociado a un cliente.'], 409);
        }

        $datos = $request->validate([
            'email' => [
                Rule::requiredIf(blank($lead->email)),
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
        ]);

        $email = $datos['email'] ?? $lead->email;

        if (blank($email)) {
            throw ValidationException::withMessages([
                'email' => 'Se necesita un correo electrónico para crear el acceso del cliente.',
            ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya pertenece a una cuenta. Usa otro correo o revisa la cuenta existente.',
            ]);
        }

        $client = DB::transaction(function () use ($lead, $email) {
            $user = new User;
            $user->name = $lead->nombre;
            $user->email = $email;
            $user->password = Hash::make(Str::random(64));
            $user->is_client = true;
            $user->save();

            if ($lead->email !== $email) {
                $lead->update(['email' => $email]);
            }

            return Client::create([
                'user_id' => $user->id,
                'lead_id' => $lead->id,
                'nombre' => $lead->nombre,
                'empresa' => $lead->empresa,
            ]);
        });

        return response()->json($client->load('user:id,name,email'), 201);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $clients = Client::query()
            ->with([
                'user:id,name,email,is_active,must_change_password,created_at',
                'lead:id,nombre,email,telefono,created_at',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('nombre', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('lead', function ($leadQuery) use ($search) {
                            $leadQuery->where('telefono', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->get();

        return response()->json($clients);
    }

    public function show(Client $client)
    {
        $client->load([
            'user:id,name,email,is_active,must_change_password,created_at',
            'lead',
            'projects.cotizacion',
            'projects.payments' => fn ($query) => $query
                ->where('status', 'pagado')
                ->with(['milestone:id,titulo', 'receipt:id,payment_id,original_name'])
                ->latest(),
        ]);

        $requests = Lead::query()
            ->where(function ($query) use ($client) {
                $query->where('email', $client->user->email);
                if ($client->lead_id) {
                    $query->orWhere('id', $client->lead_id);
                }
            })
            ->with(['cotizaciones' => fn ($query) => $query->latest()])
            ->latest()
            ->get();

        return response()->json($client->setRelation('requests', $requests));
    }

    public function update(Request $request, Client $client)
    {
        $client->loadMissing(['user', 'lead']);

        $datos = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'empresa' => 'sometimes|nullable|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($client->user_id),
            ],
            'telefono' => 'sometimes|nullable|string|max:40',
        ]);

        DB::transaction(function () use ($client, $datos) {
            if (array_key_exists('nombre', $datos)) {
                $client->nombre = $datos['nombre'];
                $client->user->name = $datos['nombre'];
                $client->user->save();
                if ($client->lead) {
                    $client->lead->nombre = $datos['nombre'];
                }
            }

            if (array_key_exists('empresa', $datos)) {
                $client->empresa = $datos['empresa'];
                if ($client->lead) {
                    $client->lead->empresa = $datos['empresa'];
                }
            }

            if (array_key_exists('email', $datos)) {
                $client->user->email = $datos['email'];
                $client->user->save();
                if ($client->lead) {
                    $client->lead->email = $datos['email'];
                }
            }

            if (array_key_exists('telefono', $datos) && $client->lead) {
                $client->lead->telefono = $datos['telefono'];
            }

            $client->save();
            $client->lead?->save();
        });

        return response()->json($client->fresh()->load([
            'user:id,name,email,is_active,must_change_password,created_at',
            'lead:id,nombre,email,telefono,created_at',
        ]));
    }

    public function updateStatus(Request $request, Client $client)
    {
        $datos = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $user = $client->user;
        abort_unless($user->is_client, 409, 'La cuenta asociada no es una cuenta de cliente.');

        $user->is_active = $datos['is_active'];
        $user->save();

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json([
            'mensaje' => $user->is_active ? 'Cuenta activada.' : 'Cuenta desactivada y sesiones cerradas.',
            'is_active' => $user->is_active,
        ]);
    }

    public function updatePassword(Request $request, Client $client)
    {
        $datos = $request->validate([
            'password' => 'required|string|min:12|confirmed',
            'force_change' => 'required|boolean',
        ]);

        $user = $client->user;
        abort_unless($user->is_client, 409, 'La cuenta asociada no es una cuenta de cliente.');

        $user->password = $datos['password'];
        $user->must_change_password = $datos['force_change'];
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'mensaje' => 'Contraseña actualizada. Comparte la contraseña temporal de forma segura.',
        ]);
    }

    public function sendPasswordResetLink(Client $client)
    {
        $user = $client->user;
        abort_unless($user->is_client && $user->is_active, 409, 'Solo se pueden restablecer cuentas de cliente activas.');

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'mensaje' => __($status),
                'email_enviado' => false,
            ], $status === Password::RESET_THROTTLED ? 429 : 422);
        }

        return response()->json([
            'mensaje' => 'Enlace de restablecimiento enviado al correo del cliente.',
            'email_enviado' => true,
        ]);
    }
}
