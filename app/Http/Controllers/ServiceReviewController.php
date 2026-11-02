<?php

namespace App\Http\Controllers;

use App\Models\ServiceReview;
use App\Models\Servicio;
use Illuminate\Http\Request;

class ServiceReviewController extends Controller
{
    public function publicos()
    {
        return response()->json(
            ServiceReview::query()
                ->where('aprobada', true)
                ->whereHas('service', fn ($query) => $query->where('activo', true))
                ->with('service:id,nombre,slug')
                ->select(['id', 'servicio_id', 'nombre', 'calificacion', 'titulo', 'comentario', 'created_at'])
                ->latest()
                ->limit(6)
                ->get()
        );
    }

    public function index(Servicio $servicio)
    {
        return response()->json(
            $servicio->reviews()
                ->where('aprobada', true)
                ->select(['id', 'servicio_id', 'nombre', 'calificacion', 'titulo', 'comentario', 'created_at'])
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, Servicio $servicio)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'calificacion' => ['required', 'integer', 'between:1,5'],
            'titulo' => ['nullable', 'string', 'max:160'],
            'comentario' => ['required', 'string', 'max:2000'],
        ]);
        $review = $servicio->reviews()->create($data);

        return response()->json([
            'mensaje' => 'Reseña recibida y pendiente de moderación.',
            'id' => $review->id,
        ], 201);
    }

    public function adminIndex()
    {
        return response()->json(
            ServiceReview::with('service:id,nombre')->latest()->get()
        );
    }

    public function moderate(Request $request, ServiceReview $serviceReview)
    {
        $data = $request->validate(['aprobada' => ['required', 'boolean']]);
        $serviceReview->update($data);

        return response()->json($serviceReview->load('service:id,nombre'));
    }

    public function destroy(ServiceReview $serviceReview)
    {
        $serviceReview->delete();

        return response()->json(['mensaje' => 'Reseña eliminada']);
    }
}
