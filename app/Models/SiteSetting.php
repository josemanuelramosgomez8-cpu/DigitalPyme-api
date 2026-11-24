<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function defaults(): array
    {
        return [
            'seo' => [
                'title' => 'DigitalPyme | Agencia digital para pymes en Lima',
                'description' => 'Creamos páginas web, tiendas en línea y estrategias digitales para pymes en Lima, Perú.',
                'slug' => '',
                'og_image' => '',
            ],
            'brand' => [
                'logo_url' => '',
            ],
            'contact' => [
                'whatsapp_phone' => null,
            ],
            'theme' => [
                'dark' => '#102c2c',
                'lime' => '#c9f45b',
                'cream' => '#f6f4ed',
                'font_sans' => 'Manrope',
                'font_display' => 'Playfair Display',
            ],
            'coverage' => [
                'digital_mode' => 'virtual_presencial_lima',
                'shipping_zones' => [],
                'shipping_methods' => [
                    ['key' => 'recojo', 'nombre' => 'Recojo', 'cost' => 0, 'delivery_days' => 0, 'enabled' => true],
                    ['key' => 'estandar', 'nombre' => 'Estándar', 'cost' => 12, 'delivery_days' => 3, 'enabled' => true],
                    ['key' => 'express', 'nombre' => 'Express', 'cost' => 20, 'delivery_days' => 1, 'enabled' => true],
                    ['key' => 'same_day', 'nombre' => 'Same-day', 'cost' => 28, 'delivery_days' => 0, 'enabled' => true],
                ],
                'free_shipping_threshold' => 200,
            ],
            'legal' => [
                'privacy' => <<<'POLICY'
POLÍTICA DE PRIVACIDAD DE DIGITALPYME (Ley N.° 29733)

DigitalPyme trata los datos personales que las personas comparten al solicitar información o una cotización, agendar una reunión, contratar un servicio, usar el portal de clientes, enviar una valoración o comunicarse con el equipo.

Los datos pueden incluir nombre, empresa, correo electrónico, teléfono, servicios de interés, mensajes, datos necesarios para una cuenta o proyecto, archivos compartidos y comprobantes de pago cuando corresponda. No envíes información sensible que no sea necesaria para tu solicitud.

Usamos estos datos para responder consultas, preparar propuestas, coordinar reuniones, prestar y administrar servicios, gestionar cuentas y proyectos, verificar pagos cuando corresponda, brindar soporte y cumplir obligaciones aplicables.

Solo debe acceder a los datos el personal que los necesite para esas finalidades. Para operar el sitio y prestar los servicios, puede ser necesario utilizar proveedores de alojamiento, correo, almacenamiento de archivos, comunicación o procesamiento de pagos. Cada proveedor trata los datos conforme a sus propias condiciones y medidas; algunos podrían procesarlos desde otros países.

Conservamos los datos durante el tiempo necesario para atender la solicitud y gestionar la relación de servicio, y por el tiempo adicional que exijan obligaciones legales o la resolución de reclamos. La persona puede solicitar la revisión o eliminación de sus datos, sujeta a las obligaciones que requieran conservarlos.

Para consultas sobre privacidad o para ejercer los derechos que correspondan, escribe a hola@digitalpyme.pe. Incluye información suficiente para identificar la solicitud; no envíes contraseñas ni datos completos de tarjetas de pago.

DigitalPyme aplica medidas razonables para proteger la información, aunque ningún medio de transmisión o almacenamiento puede garantizar seguridad absoluta. Los enlaces a servicios externos se rigen también por las políticas de sus respectivos proveedores.

Antes de publicar esta política, el titular del sitio debe verificar que la identidad legal del responsable, los proveedores utilizados, los plazos de conservación y el canal de atención descritos correspondan a sus prácticas reales y completar cualquier requisito legal aplicable.

[REVISAR LEGAL: completar la identidad del responsable, bancos de datos, encargados, transferencias, plazos y mecanismos aplicables antes de publicar.]
POLICY,
                'privacy_published' => false,
                'version' => config('services.digitalpyme.legal_policy_version', '2026-11-19'),
                'warranty' => 'La garantía y el alcance del soporte se detallan en cada propuesta aprobada. Antes de contratar, confirma por escrito las condiciones aplicables a tu proyecto.',
                'terms' => "Estas condiciones describen el uso del sitio y la contratación de los servicios y productos de DigitalPyme. Las propuestas aprobadas detallan el alcance, entregables, plazos, precio y condiciones particulares; si existe una diferencia, solicita una aclaración por escrito antes de pagar. El trabajo comienza después de la aceptación formal y del cumplimiento de las condiciones indicadas en la propuesta.\n\nLa persona que compra debe proporcionar información correcta y conservar sus comprobantes. Los plazos dependen de la entrega oportuna de materiales, accesos y aprobaciones del cliente. DigitalPyme informará cualquier cambio relevante antes de aplicarlo.\n\n[REVISAR LEGAL: completar razón social, RUC, domicilio, jurisdicción, canales de atención y condiciones aplicables a consumidores antes de publicar.]",
                'payments' => "Los precios base de la tienda están en soles (PEN). Si se muestra una conversión referencial a dólares (USD), se calcula con el tipo de cambio configurado y el pedido se confirma y cobra en PEN; el monto exacto en PEN, los impuestos y el envío se muestran antes de confirmar. El IGV y cualquier descuento se desglosan en el resumen. Los medios disponibles pueden incluir tarjeta procesada por Mercado Pago, transferencia bancaria y Yape/Plin, según su configuración.\n\nLos pagos manuales quedan pendientes hasta que DigitalPyme revise el comprobante. Los pedidos impagos pueden vencer en el plazo informado durante la compra. El resumen enviado por correo es informativo y no reemplaza el comprobante electrónico que corresponda.\n\n[REVISAR LEGAL: verificar precios con impuestos, emisión de comprobantes, medios y plazos de pago vigentes.]",
                'returns' => "Puedes solicitar la devolución de un pedido entregado desde el Portal cliente e indicar el motivo. El equipo revisará la solicitud y te informará si fue aprobada. La resolución puede ser reventa, reparación, desecho o reembolso, según la inspección y las condiciones aplicables. Esta guía no limita los derechos que correspondan conforme a la normativa vigente.\n\n[REVISAR LEGAL: definir plazos, exclusiones y procedimiento de cambios, cancelaciones y reembolsos antes de publicar.]",
                'service_changes' => "Servicios digitales: la propuesta aceptada define el alcance, entregables, plazos, pagos y revisiones incluidas. Las correcciones se evalúan frente a ese alcance; una función, entrega o cambio nuevo puede requerir una cotización y aprobación antes de iniciar trabajo adicional. Puedes pedir una reprogramación o cancelación mediante el Portal cliente, indicando el proyecto y el motivo. DigitalPyme revisará el avance realizado, los pagos y las condiciones aceptadas, y comunicará por escrito la decisión y, si corresponde, el monto y la moneda de cualquier reembolso o compensación. Enviar una solicitud no confirma automáticamente una cancelación ni un reembolso. Los plazos aplicables se informarán en la propuesta o durante la atención de la solicitud. Esto no limita los derechos que correspondan conforme a la normativa vigente.\n\n[REVISAR LEGAL: validar estas condiciones con la práctica comercial y la normativa aplicable antes de publicar.]",
                'revisions' => "La cantidad de revisiones, los entregables y las fechas se especifican en la propuesta aceptada. El cliente debe enviar observaciones consolidadas dentro del plazo acordado. La aprobación de una entrega confirma que se recibió el hito descrito; las solicitudes fuera del alcance pueden requerir una nueva cotización.\n\n[REVISAR LEGAL: definir plazos de revisión, aceptación tácita y costos de cambios adicionales.]",
                'intellectual_property' => "Los derechos sobre materiales preexistentes pertenecen a sus titulares. La titularidad o licencia de uso de los entregables se determinará en la propuesta o contrato aceptado y podrá depender del pago total. El cliente declara contar con autorización para los materiales que entrega a DigitalPyme.\n\n[REVISAR LEGAL: establecer titularidad, licencias, portafolio, tipografías, recursos de terceros y momento de transferencia.]",
                'promotions' => "Las promociones deben mostrar su vigencia, productos o servicios incluidos, restricciones y precio final antes de contratar. Ejemplo referencial: campaña de Navidad con 20 % de descuento, sujeta a fechas, cupos y servicios elegibles que se publiquen para esa campaña.\n\n[REVISAR LEGAL: no publicar el ejemplo como oferta vigente; completar vigencia, stock/cupos, precio de referencia, restricciones y condiciones de cada campaña.]",
                'complaints_url' => config('services.digitalpyme.complaint_book_url'),
            ],
        ];
    }

    public static function publicSettings(): array
    {
        $settings = static::settings();
        if (! ($settings['legal']['privacy_published'] ?? false)) {
            $settings['legal']['privacy'] = null;
        }

        return $settings;
    }

    public static function adminSettings(): array
    {
        return static::settings();
    }

    private static function settings(): array
    {
        $saved = static::query()->find('public')?->value ?? [];
        $settings = array_replace_recursive(static::defaults(), $saved);
        if (
            ($settings['theme']['font_sans'] ?? null) === 'DM Sans'
            && ($settings['theme']['font_display'] ?? null) === 'DM Serif Display'
        ) {
            $settings['theme']['font_sans'] = 'Manrope';
            $settings['theme']['font_display'] = 'Playfair Display';
        }
        $settings['seo']['slug'] ??= '';
        $settings['seo']['og_image'] ??= '';
        $settings['brand']['logo_url'] ??= '';
        $defaultMethods = static::defaults()['coverage']['shipping_methods'];
        $savedMethods = $settings['coverage']['shipping_methods'] ?? [];
        $savedMethodsByKey = collect($savedMethods)->keyBy('key');
        $settings['coverage']['shipping_methods'] = collect($defaultMethods)
            ->map(fn (array $default) => array_replace($default, (array) ($savedMethodsByKey->get($default['key']) ?? [])))
            ->values()
            ->all();
        $settings['coverage']['free_shipping_threshold'] ??= static::defaults()['coverage']['free_shipping_threshold'];
        $settings['legal']['returns'] ??= static::defaults()['legal']['returns'];
        $settings['legal']['service_changes'] ??= static::defaults()['legal']['service_changes'];
        $settings['legal']['payments'] ??= static::defaults()['legal']['payments'];
        $settings['legal']['revisions'] ??= static::defaults()['legal']['revisions'];
        $settings['legal']['intellectual_property'] ??= static::defaults()['legal']['intellectual_property'];
        $settings['legal']['promotions'] ??= static::defaults()['legal']['promotions'];
        $settings['legal']['version'] = config('services.digitalpyme.legal_policy_version', $settings['legal']['version'] ?? '2026-11-19');
        $settings['legal']['complaints_url'] = config('services.digitalpyme.complaint_book_url');

        return $settings;
    }

    public static function savePublicSettings(array $settings): array
    {
        $merged = static::adminSettings();
        foreach ($settings as $section => $values) {
            $merged[$section] = is_array($values) && is_array($merged[$section] ?? null)
                ? array_replace($merged[$section], $values)
                : $values;
        }

        static::query()->updateOrCreate(
            ['key' => 'public'],
            ['value' => $merged],
        );

        return static::adminSettings();
    }
}
