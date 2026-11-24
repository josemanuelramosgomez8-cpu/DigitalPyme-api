<?php

namespace App\Http\Controllers;

use App\Models\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServicioController extends Controller
{
    // Público: solo los servicios activos, para la página web
    public function publicos()
    {
        return response()->json(
            Servicio::where('activo', true)->with(['category', 'subcategory'])
                ->withAvg(['reviews as calificacion_promedio' => fn ($query) => $query->where('aprobada', true)], 'calificacion')
                ->withCount(['reviews as total_resenas' => fn ($query) => $query->where('aprobada', true)])
                ->orderBy('orden')->orderBy('id')->get()
        );
    }

    public function show(string $slug)
    {
        $servicio = Servicio::where('slug', $slug)
            ->where('activo', true)
            ->with(['category', 'subcategory'])
            ->withAvg(['reviews as calificacion_promedio' => fn ($query) => $query->where('aprobada', true)], 'calificacion')
            ->withCount(['reviews as total_resenas' => fn ($query) => $query->where('aprobada', true)])
            ->firstOrFail();

        $servicio->setRelation('resenas', $servicio->reviews()
            ->where('aprobada', true)
            ->select(['id', 'servicio_id', 'nombre', 'calificacion', 'titulo', 'comentario', 'created_at'])
            ->latest()
            ->get());
        $servicio->setRelation('proyectos_relacionados', \App\Models\PortfolioProject::where('related_service_id', $servicio->id)
            ->where('activo', true)->orderBy('orden')->get());

        return response()->json($servicio);
    }

    // Administrador: todos, incluidos los desactivados
    public function index()
    {
        return response()->json(
            Servicio::with(['category', 'subcategory', 'planificacion'])
                ->orderBy('orden')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre'       => 'required|string|max:255',
            'descripcion'  => 'nullable|string|max:2000',
            'precio_desde' => 'nullable|numeric|min:0|max:99999999',
            'duracion'     => 'nullable|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', 'unique:servicios,slug'],
            'descripcion_larga' => 'nullable|string|max:10000',
            'incluye' => 'nullable|array|max:30',
            'incluye.*' => 'string|max:255',
            'etiqueta' => ['nullable', Rule::in(['Nuevo', 'Más vendido', '-20%', 'Destacado'])],
            'category_id' => 'nullable|exists:service_categories,id',
            'subcategory_id' => 'nullable|exists:service_subcategories,id',
            'planificacion' => 'sometimes|nullable|array:prioridad,estandarizacion,estado_preparacion,tiempo_estimado_dias',
            'planificacion.prioridad' => ['nullable', 'string', Rule::in(['alta', 'media', 'baja'])],
            'planificacion.estandarizacion' => ['nullable', 'string', Rule::in(['plantilla', 'a_medida'])],
            'planificacion.estado_preparacion' => ['nullable', 'string', Rule::in(['disponible', 'en_preparacion', 'pausado'])],
            'planificacion.tiempo_estimado_dias' => 'nullable|integer|min:1|max:3650',
            'activo'       => 'boolean',
            'orden'        => 'integer|min:0',
        ]);
        $planificacion = $datos['planificacion'] ?? null;
        unset($datos['planificacion']);

        if (!empty($datos['subcategory_id'])) {
            $subcategory = \App\Models\ServiceSubcategory::findOrFail($datos['subcategory_id']);
            if (!empty($datos['category_id']) && (int) $datos['category_id'] !== $subcategory->category_id) {
                return response()->json(['message' => 'La subcategoría no pertenece a la categoría seleccionada.'], 422);
            }
            $datos['category_id'] = $subcategory->category_id;
        }
        $datos['slug'] = $this->availableSlug($datos['slug'] ?? $datos['nombre']);

        return DB::transaction(function () use ($datos, $planificacion) {
            $servicio = Servicio::create($datos);
            if (is_array($planificacion)) {
                $servicio->planificacion()->create($planificacion);
            }

            return response()->json(
                $servicio->load(['category', 'subcategory', 'planificacion']),
                201
            );
        });
    }

    public function update(Request $request, Servicio $servicio)
    {
        $datos = $request->validate([
            'nombre'       => 'sometimes|required|string|max:255',
            'descripcion'  => 'nullable|string|max:2000',
            'precio_desde' => 'nullable|numeric|min:0|max:99999999',
            'duracion'     => 'nullable|string|max:255',
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('servicios', 'slug')->ignore($servicio->id)],
            'descripcion_larga' => 'nullable|string|max:10000',
            'incluye' => 'nullable|array|max:30',
            'incluye.*' => 'string|max:255',
            'etiqueta' => ['nullable', Rule::in(['Nuevo', 'Más vendido', '-20%', 'Destacado'])],
            'category_id' => 'nullable|exists:service_categories,id',
            'subcategory_id' => 'nullable|exists:service_subcategories,id',
            'planificacion' => 'sometimes|nullable|array:prioridad,estandarizacion,estado_preparacion,tiempo_estimado_dias',
            'planificacion.prioridad' => ['nullable', 'string', Rule::in(['alta', 'media', 'baja'])],
            'planificacion.estandarizacion' => ['nullable', 'string', Rule::in(['plantilla', 'a_medida'])],
            'planificacion.estado_preparacion' => ['nullable', 'string', Rule::in(['disponible', 'en_preparacion', 'pausado'])],
            'planificacion.tiempo_estimado_dias' => 'nullable|integer|min:1|max:3650',
            'activo'       => 'boolean',
            'orden'        => 'integer|min:0',
        ]);
        $planificacion = $datos['planificacion'] ?? null;
        $actualizaPlanificacion = array_key_exists('planificacion', $datos) && is_array($planificacion);
        unset($datos['planificacion']);

        $categoryId = $datos['category_id'] ?? $servicio->category_id;
        $subcategoryId = $datos['subcategory_id'] ?? $servicio->subcategory_id;
        if (array_key_exists('category_id', $datos) && !array_key_exists('subcategory_id', $datos) && $subcategoryId !== null) {
            $currentSubcategory = \App\Models\ServiceSubcategory::find($subcategoryId);
            if ($currentSubcategory && (int) $categoryId !== $currentSubcategory->category_id) {
                $datos['subcategory_id'] = null;
                $subcategoryId = null;
            }
        }
        if ($subcategoryId !== null) {
            $subcategory = \App\Models\ServiceSubcategory::findOrFail($subcategoryId);
            if ((int) $categoryId !== $subcategory->category_id) {
                return response()->json(['message' => 'La subcategoría no pertenece a la categoría seleccionada.'], 422);
            }
        }
        if (array_key_exists('slug', $datos) && !$datos['slug']) {
            $datos['slug'] = $this->availableSlug($datos['nombre'] ?? $servicio->nombre, $servicio->id);
        }

        return DB::transaction(function () use ($datos, $planificacion, $actualizaPlanificacion, $servicio) {
            $servicio->update($datos);
            if ($actualizaPlanificacion) {
                $servicio->planificacion()->updateOrCreate([], $planificacion);
            }

            return response()->json(
                $servicio->fresh(['category', 'subcategory', 'planificacion'])
            );
        });
    }

    public function destroy(Servicio $servicio)
    {
        $servicio->delete();

        return response()->json(['mensaje' => 'Servicio eliminado']);
    }

    private function availableSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'servicio';
        $slug = $base;
        $suffix = 2;
        while (Servicio::where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}