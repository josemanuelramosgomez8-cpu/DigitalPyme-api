<?php

namespace App\Http\Controllers;

use App\Models\PortfolioProject;
use Illuminate\Http\Request;

class PortfolioProjectController extends Controller
{
    public function publicos()
    {
        return response()->json(
            PortfolioProject::where('activo', true)->orderBy('orden')->orderBy('id')->get()
        );
    }

    public function index()
    {
        return response()->json(
            PortfolioProject::orderBy('orden')->orderBy('id')->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'categoria' => 'required|string|max:255',
            'resultado' => 'required|string|max:255',
            'descripcion_larga' => 'nullable|string|max:5000',
            'desafio' => 'nullable|string|max:3000',
            'solucion' => 'nullable|string|max:3000',
            'entregables' => 'nullable|array|max:12',
            'entregables.*' => 'required|string|max:160',
            'tecnologias' => 'nullable|array|max:12',
            'tecnologias.*' => 'required|string|max:80',
            'imagen' => 'required|string|max:2000',
            'imagen_alt' => 'nullable|string|max:255',
            'etiqueta' => ['nullable', \Illuminate\Validation\Rule::in(['Nuevo', 'Más vendido', '-20%', 'Destacado'])],
            'related_service_id' => 'nullable|exists:servicios,id',
            'activo' => 'sometimes|boolean',
            'orden' => 'sometimes|integer|min:0',
        ]);

        return response()->json(PortfolioProject::create($datos), 201);
    }

    public function update(Request $request, PortfolioProject $portfolioProject)
    {
        $datos = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'categoria' => 'sometimes|required|string|max:255',
            'resultado' => 'sometimes|required|string|max:255',
            'descripcion_larga' => 'sometimes|nullable|string|max:5000',
            'desafio' => 'sometimes|nullable|string|max:3000',
            'solucion' => 'sometimes|nullable|string|max:3000',
            'entregables' => 'sometimes|nullable|array|max:12',
            'entregables.*' => 'required|string|max:160',
            'tecnologias' => 'sometimes|nullable|array|max:12',
            'tecnologias.*' => 'required|string|max:80',
            'imagen' => 'sometimes|required|string|max:2000',
            'imagen_alt' => 'sometimes|nullable|string|max:255',
            'etiqueta' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(['Nuevo', 'Más vendido', '-20%', 'Destacado'])],
            'related_service_id' => 'sometimes|nullable|exists:servicios,id',
            'activo' => 'sometimes|boolean',
            'orden' => 'sometimes|integer|min:0',
        ]);

        $portfolioProject->update($datos);

        return response()->json($portfolioProject);
    }

    public function destroy(PortfolioProject $portfolioProject)
    {
        $portfolioProject->delete();

        return response()->json(['mensaje' => 'Proyecto de portafolio eliminado']);
    }
}
