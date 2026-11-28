<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Servicio;
use App\Support\AdminJsonPagination;
use App\Support\SolicitudNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public const ESTADOS = ['nuevo', 'contactado', 'cotizado', 'ganado', 'perdido'];

    // Público: lo usan los formularios de la página
    public function store(Request $request, SolicitudNotifier $notifier)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'empresa' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|required_without:telefono',
            'telefono' => 'nullable|string|max:30|required_without:email',
            'servicio' => 'nullable|string|max:255',
            'mensaje' => 'nullable|string|max:2000',
        ]);

        $datos['servicio'] = $datos['servicio'] ?? 'Sin especificar';
        $datos['inversion_estimada'] = Servicio::query()
            ->where('nombre', $datos['servicio'])
            ->where('activo', true)
            ->value('precio_desde');

        $lead = Lead::create($datos);
        $notificacion = $notifier->notify($lead);

        return response()->json([
            'mensaje' => 'Lead guardado correctamente',
            'lead' => $lead,
            ...$notificacion,
        ], 201);
    }

    // Protegido: solo el administrador
    public function index(Request $request)
    {
        $leads = AdminJsonPagination::paginate(Lead::query()
            ->with(['cotizaciones', 'client:id,lead_id,user_id'])
            ->when($request->query('estado'), fn ($q, $estado) => $q->where('estado', $estado))
            ->latest(), $request);

        return AdminJsonPagination::respond($leads);
    }

    // Protegido: cambiar el estado de un lead
    public function update(Request $request, Lead $lead)
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in(self::ESTADOS)],
        ]);

        $lead->update($datos);

        return response()->json($lead);
    }
}
