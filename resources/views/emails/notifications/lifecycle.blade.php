@component('mail::message')
# DigitalPyme

{{ $intro }}

@foreach ($details as $detail)
- {{ $detail }}
@endforeach

Gracias por confiar en DigitalPyme.

@if ($unsubscribeUrl)
Si no deseas recibir recordatorios, puedes darte de baja desde el enlace incluido en este correo.
@endif
@endcomponent
