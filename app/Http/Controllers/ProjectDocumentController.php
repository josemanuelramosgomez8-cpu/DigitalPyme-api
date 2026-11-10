<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectDocumentController extends Controller
{
    private const VALIDATION_RULE = 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240';
    private const TYPE_RULE = 'sometimes|string|in:documento,comprobante';

    public function clientIndex(Request $request, Project $project)
    {
        $this->ensureClientOwnsProject($request, $project);

        return response()->json($project->documents()->latest()->get());
    }

    public function clientStore(Request $request, Project $project)
    {
        $this->ensureClientOwnsProject($request, $project);
        $request->validate([
            'file' => self::VALIDATION_RULE,
            'tipo' => self::TYPE_RULE,
        ]);

        return $this->store($request, $project);
    }

    public function clientStorePaymentReceipt(Request $request, Project $project, ProjectPayment $payment)
    {
        $this->ensureClientOwnsProject($request, $project);
        abort_unless($payment->project_id === $project->id, 404);

        $request->validate(['file' => self::VALIDATION_RULE]);

        return $this->store($request, $project, 'comprobante', $payment->id);
    }

    public function clientDownload(Request $request, Project $project, ProjectDocument $document)
    {
        $this->ensureClientOwnsProject($request, $project);
        $this->ensureDocumentBelongsToProject($project, $document);

        return $this->download($document);
    }

    public function adminIndex(Project $project)
    {
        return response()->json($project->documents()->latest()->get());
    }

    public function adminStore(Request $request, Project $project)
    {
        $request->validate([
            'file' => self::VALIDATION_RULE,
            'tipo' => self::TYPE_RULE,
        ]);

        return $this->store($request, $project);
    }

    public function adminDownload(Project $project, ProjectDocument $document)
    {
        $this->ensureDocumentBelongsToProject($project, $document);

        return $this->download($document);
    }

    public function adminDestroy(Project $project, ProjectDocument $document)
    {
        $this->ensureDocumentBelongsToProject($project, $document);

        if (!Storage::disk('uploads')->delete($document->file_path)) {
            abort(500, 'No se pudo eliminar el archivo del almacenamiento.');
        }

        $document->delete();

        return response()->json(['mensaje' => 'Documento eliminado.']);
    }

    private function ensureClientOwnsProject(Request $request, Project $project): void
    {
        abort_unless($project->client_id === $request->user()->client->id, 404);
    }

    private function ensureDocumentBelongsToProject(Project $project, ProjectDocument $document): void
    {
        abort_unless($document->project_id === $project->id, 404);
    }

    private function store(
        Request $request,
        Project $project,
        string $tipo = 'documento',
        ?int $paymentId = null,
    )
    {
        $file = $request->file('file');
        $path = $file->store("projects/{$project->id}", 'uploads');

        if (!is_string($path) || $path === '') {
            abort(500, 'No se pudo guardar el archivo.');
        }

        try {
            $document = DB::transaction(function () use ($request, $project, $file, $path, $tipo, $paymentId) {
                if ($paymentId !== null) {
                    $payment = ProjectPayment::whereKey($paymentId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (! in_array($payment->method, ProjectPayment::MANUAL_METHODS, true) || $payment->status !== 'pendiente') {
                        abort(422, 'Este pago no admite el envío de un comprobante.');
                    }

                    if ($payment->receipt()->exists()) {
                        abort(409, 'Este pago ya tiene un comprobante adjunto.');
                    }
                }

                $document = $project->documents()->create([
                    'uploaded_by_user_id' => $request->user()->id,
                    'payment_id' => $paymentId,
                    'original_name' => $file->getClientOriginalName(),
                    'tipo' => $tipo,
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);

                if ($paymentId !== null) {
                    $payment->update(['status' => 'comprobante_enviado']);
                }

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk('uploads')->delete($path);

            throw $exception;
        }

        return response()->json($document, 201);
    }

    private function download(ProjectDocument $document)
    {
        if (!Storage::disk('uploads')->exists($document->file_path)) {
            abort(404, 'No se encontró el archivo solicitado.');
        }

        return Storage::disk('uploads')->download($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
