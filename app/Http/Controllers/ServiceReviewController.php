<?php

namespace App\Http\Controllers;

use App\Models\ServiceReview;
use App\Models\Servicio;
use App\Support\AdminJsonPagination;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PDOException;

class ServiceReviewController extends Controller
{
    public function publicos(Request $request)
    {
        $pagination = $request->validate([
            'offset' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $cache = Cache::store('file');
        $staleCacheKey = 'public-service-reviews:last-successful';
        $fallbackCacheKey = 'public-service-reviews:database-outage';
        $usingFallback = false;

        try {
            $reviews = ServiceReview::query()
                ->where('aprobada', true)
                ->whereHas('service', fn ($query) => $query->where('activo', true))
                ->with('service:id,nombre,slug')
                ->select(['id', 'servicio_id', 'nombre', 'calificacion', 'titulo', 'comentario', 'created_at'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $cache->put($staleCacheKey, $reviews, now()->addDays(7));
            $cache->forget($fallbackCacheKey);
        } catch (QueryException|PDOException $exception) {
            $usingFallback = true;
            $reviews = $cache->remember($fallbackCacheKey, now()->addMinutes(10), function () use ($cache, $staleCacheKey, $exception) {
                $cachedReviews = $cache->get($staleCacheKey);
                if ($cachedReviews === null) {
                    throw $exception;
                }

                return $cachedReviews;
            });
        }

        $offset = $pagination['offset'] ?? 0;
        $limit = $pagination['limit'] ?? 6;

        return response()->json([
            'data' => $reviews->slice($offset, $limit)->values(),
            'meta' => [
                'total' => $reviews->count(),
                'offset' => $offset,
                'limit' => $limit,
            ],
            'status' => $usingFallback ? 'degraded' : 'success',
        ]);
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

    public function adminIndex(Request $request)
    {
        $reviews = AdminJsonPagination::paginate(
            ServiceReview::with('service:id,nombre')->latest(),
            $request,
        );

        return AdminJsonPagination::respond($reviews);
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
