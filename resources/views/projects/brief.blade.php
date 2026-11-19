<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brief de proyecto | DigitalPyme</title>
    <style>
        body { margin: 0; padding: 32px 16px; background: #f6f4ed; color: #102c2c; font: 16px/1.6 Arial, sans-serif; }
        main { max-width: 720px; margin: auto; padding: 32px; border-radius: 12px; background: white; }
        label { display: block; margin: 20px 0; font-weight: bold; }
        textarea { display: block; width: 100%; min-height: 100px; margin-top: 8px; padding: 10px; box-sizing: border-box; }
        button { padding: 12px 20px; border: 0; border-radius: 6px; background: #102c2c; color: white; font-weight: bold; }
        .error { color: #a11; }
    </style>
</head>
<body>
<main>
    <h1>Brief de {{ $project->nombre }}</h1>
    <p>Cuéntanos sobre tus objetivos y preferencias. Este formulario está vinculado a tu enlace seguro.</p>
    @if ($brief)
        <p>Ya recibimos una respuesta el {{ $brief->submitted_at->format('d/m/Y H:i') }}. Puedes actualizarla aquí.</p>
    @endif
    @if ($errors->any())
        <div class="error" role="alert">
            <p>Revisa los campos indicados:</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <form method="post" action="{{ request()->fullUrl() }}">
        @csrf
        <label>¿Qué objetivo debe cumplir el proyecto?
            <textarea name="answers[objetivo]" maxlength="2000" required>{{ old('answers.objetivo', $brief?->answers['objetivo'] ?? '') }}</textarea>
        </label>
        <label>¿A qué público quieres llegar?
            <textarea name="answers[publico]" maxlength="2000" required>{{ old('answers.publico', $brief?->answers['publico'] ?? '') }}</textarea>
        </label>
        <label>¿Qué tono o estilo visual prefieres?
            <textarea name="answers[tono]" maxlength="1000">{{ old('answers.tono', $brief?->answers['tono'] ?? '') }}</textarea>
        </label>
        <label>Comparte referencias o sitios que te gusten
            <textarea name="answers[referencias]" maxlength="2000">{{ old('answers.referencias', $brief?->answers['referencias'] ?? '') }}</textarea>
        </label>
        <button type="submit">Enviar brief</button>
    </form>
</main>
</body>
</html>
