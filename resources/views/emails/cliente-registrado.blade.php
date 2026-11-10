<h1>Nuevo cliente registrado</h1>

<p>Se creó una cuenta de cliente en el portal de DigitalPyme.</p>
<p><strong>Nombre:</strong> {{ $client->nombre }}</p>
<p><strong>Correo:</strong> {{ $client->user->email }}</p>
<p>Por seguridad, la contraseña no se comparte por correo. El cliente la eligió al registrarse.</p>
