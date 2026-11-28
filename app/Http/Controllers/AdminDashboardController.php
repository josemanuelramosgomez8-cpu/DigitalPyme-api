<?php

namespace App\Http\Controllers;

use App\Models\AgendaReservation;
use App\Models\Cotizacion;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectMonthlyCapacity;
use App\Models\ProjectPayment;
use App\Models\StockMovement;
use App\Models\StoreProduct;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
        $capacity = ProjectMonthlyCapacity::whereDate('month', $month->startOfMonth()->toDateString())->first();
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $previousMonthStart = $month->copy()->subMonth()->startOfMonth();
        $previousMonthEnd = $month->copy()->subMonth()->endOfMonth();
        $unitsSold = StockMovement::where('tipo', 'salida')
            ->whereNotNull('order_id')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('cantidad');
        $unitsReceived = StockMovement::where('tipo', 'entrada')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('cantidad');
        $unitsOut = StockMovement::where('tipo', 'salida')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('cantidad');
        $currentStock = StoreProduct::sum('stock');
        $averageStock = (max(0, $currentStock - $unitsReceived + $unitsOut) + $currentStock) / 2;
        $projectIncome = (float) ProjectPayment::where('status', 'pagado')
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->sum('amount');
        $storeIncome = (float) StoreOrder::where('payment_status', 'pagado')
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->sum('total');
        $confirmedIncome = $projectIncome + $storeIncome;
        $previousProjectIncome = (float) ProjectPayment::where('status', 'pagado')
            ->whereBetween('paid_at', [$previousMonthStart, $previousMonthEnd])
            ->sum('amount');
        $previousStoreIncome = (float) StoreOrder::where('payment_status', 'pagado')
            ->whereBetween('paid_at', [$previousMonthStart, $previousMonthEnd])
            ->sum('total');
        $previousIncome = $previousProjectIncome + $previousStoreIncome;
        $incomeChange = $previousIncome > 0
            ? round((($confirmedIncome - $previousIncome) / $previousIncome) * 100, 1)
            : null;
        $projectPaymentsPending = (float) ProjectPayment::where('status', 'comprobante_enviado')->sum('amount');
        $storePaymentsPending = (float) StoreOrder::where('payment_status', 'comprobante_enviado')->sum('total');
        $overdueProjectsQuery = Project::with('client:id,nombre')
            ->whereIn('estado', ['planificacion', 'en_progreso', 'revision'])
            ->whereNotNull('fecha_entrega')
            ->whereDate('fecha_entrega', '<', now()->toDateString());
        $overdueProjects = (clone $overdueProjectsQuery)
            ->orderBy('fecha_entrega')
            ->limit(8)
            ->get(['id', 'nombre', 'estado', 'progreso', 'fecha_entrega', 'client_id'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'nombre' => $project->nombre,
                'estado' => $project->estado,
                'progreso' => $project->progreso,
                'fecha_entrega' => $project->fecha_entrega?->toDateString(),
                'cliente' => $project->client?->nombre,
            ]);
        $overdueProjectsCount = (clone $overdueProjectsQuery)->count();
        $upcomingProjects = Project::with('client:id,nombre')
            ->whereIn('estado', ['planificacion', 'en_progreso', 'revision'])
            ->whereNotNull('fecha_entrega')
            ->whereBetween('fecha_entrega', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->orderBy('fecha_entrega')
            ->limit(8)
            ->get(['id', 'nombre', 'estado', 'progreso', 'fecha_entrega', 'client_id'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'nombre' => $project->nombre,
                'estado' => $project->estado,
                'progreso' => $project->progreso,
                'fecha_entrega' => $project->fecha_entrega?->toDateString(),
                'cliente' => $project->client?->nombre,
            ]);
        $upcomingReservations = AgendaReservation::with('slot')
            ->where('estado', 'reservada')
            ->whereHas('slot', fn ($query) => $query
                ->whereDate('date', '>=', now()->toDateString())
                ->whereDate('date', '<=', now()->addDays(7)->toDateString()))
            ->get()
            ->map(fn (AgendaReservation $reservation) => [
                'id' => $reservation->id,
                'nombre' => $reservation->nombre,
                'email' => $reservation->email,
                'telefono' => $reservation->telefono,
                'fecha' => $reservation->slot?->date?->toDateString(),
                'hora' => $reservation->slot?->time,
                'slot' => [
                    'date' => $reservation->slot?->date?->toDateString(),
                    'time' => $reservation->slot?->time,
                ],
            ])
            ->sortBy([['fecha', 'asc'], ['hora', 'asc']])
            ->take(8)
            ->values();
        $recentLeads = Lead::where('estado', 'nuevo')
            ->latest()
            ->limit(5)
            ->get(['id', 'nombre', 'empresa', 'email', 'telefono', 'servicio', 'estado', 'created_at'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'nombre' => $lead->nombre,
                'empresa' => $lead->empresa,
                'email' => $lead->email,
                'telefono' => $lead->telefono,
                'servicio' => $lead->servicio,
                'estado' => $lead->estado,
                'created_at' => $lead->created_at?->toIso8601String(),
            ]);
        $stockAlerts = StoreProduct::where('activo', true)
            ->where(fn ($query) => $query->where('stock', 0)
                ->orWhereColumn('stock', '<=', 'punto_reorden'))
            ->orderByRaw('stock asc')
            ->limit(8)
            ->get(['id', 'sku', 'nombre', 'stock', 'punto_reorden'])
            ->map(fn (StoreProduct $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'nombre' => $product->nombre,
                'stock' => $product->stock,
                'punto_reorden' => $product->punto_reorden,
                'estado' => $product->stock === 0 ? 'agotado' : 'bajo_reorden',
            ]);

        return response()->json([
            'periodo' => $month->locale('es')->translatedFormat('F Y'),
            'periodo_mes' => $month->format('Y-m'),
            'leads_total' => Lead::count(),
            'leads_nuevos' => Lead::where('estado', 'nuevo')->count(),
            'solicitudes_recientes' => $recentLeads,
            'cotizaciones_total' => Cotizacion::count(),
            'cotizaciones_pendientes' => Cotizacion::whereIn('estado', ['borrador', 'enviada'])->count(),
            'proyectos_total' => Project::count(),
            'proyectos_en_progreso' => Project::whereIn('estado', ['planificacion', 'en_progreso', 'revision'])->count(),
            'proyectos_vencidos' => $overdueProjects,
            'proximas_entregas' => $upcomingProjects,
            'proyectos_por_estado' => Project::query()
                ->selectRaw('estado, count(*) as total')
                ->groupBy('estado')
                ->pluck('total', 'estado'),
            'pagos_por_verificar' => ProjectPayment::where('status', 'comprobante_enviado')->count(),
            'pagos_tienda_por_verificar' => StoreOrder::where('payment_status', 'comprobante_enviado')->count(),
            'pagos_pendientes' => ProjectPayment::whereIn('status', ['pendiente', 'comprobante_enviado'])->count()
                + StoreOrder::whereIn('payment_status', ['pendiente', 'comprobante_enviado'])->count(),
            'pagos_pendientes_monto' => round($projectPaymentsPending + $storePaymentsPending, 2),
            'ingresos_confirmados' => round($confirmedIncome, 2),
            'ingresos_mes_anterior' => round($previousIncome, 2),
            'variacion_ingresos_porcentaje' => $incomeChange,
            'proximas_reservas' => $upcomingReservations,
            'reservas_del_mes' => AgendaReservation::where('estado', 'reservada')
                ->whereHas('slot', fn ($query) => $query->whereBetween('date', [
                    $month->copy()->startOfMonth()->toDateString(),
                    $month->copy()->endOfMonth()->toDateString(),
                ]))
                ->count(),
            'cupos_proyecto' => $capacity ? [
                'capacidad' => $capacity->capacity,
                'reservados' => $capacity->reserved,
                'disponibles' => $capacity->available,
                'alerta_pocos_cupos' => $capacity->low,
            ] : null,
            'inventario' => [
                'productos_bajo_reorden' => StoreProduct::where('stock', '>', 0)->whereColumn('stock', '<=', 'punto_reorden')->count(),
                'productos_agotados' => StoreProduct::where('stock', 0)->count(),
                'unidades_vendidas' => (int) $unitsSold,
                'rotacion' => $averageStock > 0 ? round($unitsSold / $averageStock, 2) : 0,
                'alertas' => $stockAlerts,
            ],
            'acciones_rapidas' => [
                ['clave' => 'crear_lead', 'titulo' => 'Nueva solicitud', 'ruta' => '/solicitudes'],
                ['clave' => 'crear_cliente', 'titulo' => 'Nuevo cliente', 'ruta' => '/clientes'],
                ['clave' => 'crear_cotizacion', 'titulo' => 'Crear cotización', 'ruta' => '/cotizaciones-pendientes'],
                ['clave' => 'crear_proyecto', 'titulo' => 'Crear proyecto', 'ruta' => '/proyectos'],
                ['clave' => 'revisar_pagos', 'titulo' => 'Revisar pagos', 'ruta' => '/pagos'],
            ],
        ]);
    }
}
