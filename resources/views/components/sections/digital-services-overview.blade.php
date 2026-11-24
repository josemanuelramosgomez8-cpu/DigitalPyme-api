@php
    $frontendUrl = rtrim((string) config('services.digitalpyme.frontend_url'), '/');
@endphp

<section class="dp-overview" aria-labelledby="dp-overview-title">
    <div class="dp-overview__container">
        <header class="dp-overview__header">
            <p class="dp-overview__eyebrow">Soluciones digitales para PYMES</p>
            <h2 id="dp-overview-title">Tecnología y acompañamiento para cada etapa de tu negocio</h2>
            <p>
                DigitalPyme define contigo el alcance, las herramientas y el acompañamiento
                adecuados. La plataforma y las condiciones se confirman en una propuesta.
            </p>
            @if ($frontendUrl !== '')
                <a class="dp-overview__cta" href="{{ $frontendUrl }}/catalogo-servicios">
                    Explorar servicios y solicitar una cotización
                </a>
            @endif
        </header>

        <div class="dp-overview__services" aria-label="Servicios de DigitalPyme">
            <article class="dp-overview__card">
                <span class="dp-overview__number" aria-hidden="true">01</span>
                <h3>Desarrollo web</h3>
                <p>
                    Sitios adaptables a móviles y computadoras, con una estructura
                    clara para presentar tu negocio y facilitar su administración.
                </p>
                <ul>
                    <li>CMS y edición de contenido cuando el proyecto lo requiere.</li>
                    <li>SEO técnico inicial y páginas acordadas.</li>
                    <li>Estilos y plantillas ajustados a la identidad de marca.</li>
                </ul>
            </article>

            <article class="dp-overview__card">
                <span class="dp-overview__number" aria-hidden="true">02</span>
                <h3>Tiendas online</h3>
                <p>
                    Experiencias de compra que organizan productos, pedidos y la
                    atención después de la compra.
                </p>
                <ul>
                    <li>Catálogo, carrito y gestión de pedidos según el alcance.</li>
                    <li>Inventario y opciones de envío por zona cuando corresponda.</li>
                    <li>Pagos e integraciones definidos para cada negocio.</li>
                </ul>
            </article>

            <article class="dp-overview__card">
                <span class="dp-overview__number" aria-hidden="true">03</span>
                <h3>Automatización de procesos</h3>
                <p>
                    Identificamos tareas repetitivas y planteamos flujos para reducir
                    pasos manuales en la operación.
                </p>
                <ul>
                    <li>Revisión del proceso actual y sus objetivos.</li>
                    <li>Integraciones sujetas a las herramientas y accesos disponibles.</li>
                    <li>Alcance y soporte acordados antes de iniciar.</li>
                </ul>
            </article>

            <article class="dp-overview__card">
                <span class="dp-overview__number" aria-hidden="true">04</span>
                <h3>Gestión de redes sociales</h3>
                <p>
                    Organización de la comunicación digital de tu marca, con tareas
                    y frecuencia definidas en la propuesta.
                </p>
                <ul>
                    <li>Planificación de contenido y calendario editorial.</li>
                    <li>Gestión de publicaciones en los canales acordados.</li>
                    <li>Seguimiento y entregables según el servicio contratado.</li>
                </ul>
            </article>
        </div>

        <section class="dp-overview__comparison" aria-labelledby="dp-platforms-title">
            <div class="dp-overview__comparison-heading">
                <p class="dp-overview__eyebrow">CMS y plataformas de comercio</p>
                <h3 id="dp-platforms-title">¿Qué opción encaja con tu modelo de negocio?</h3>
                <p>
                    La comparación es orientativa: el resultado depende de la configuración,
                    extensiones, alojamiento, presupuesto y experiencia del equipo.
                </p>
            </div>

            <div class="dp-overview__table-wrap" role="region" aria-label="Comparación de plataformas" tabindex="0">
                <table class="dp-overview__table">
                    <caption class="dp-overview__sr-only">Comparación de modelos, facilidad, mantenimiento y perfil recomendado</caption>
                    <thead>
                        <tr>
                            <th scope="col">Opción</th>
                            <th scope="col">Modelo y flexibilidad</th>
                            <th scope="col">Facilidad de inicio</th>
                            <th scope="col">Mantenimiento</th>
                            <th scope="col">Perfil recomendado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="dp-overview__featured-row">
                            <th scope="row">DigitalPyme</th>
                            <td>Servicio de implementación y acompañamiento; la tecnología se define según el proyecto.</td>
                            <td>Guiado: se revisan objetivos y capacidades del equipo.</td>
                            <td>Se acuerda según la plataforma y el soporte contratado.</td>
                            <td>PYMES que buscan orientación y una solución ajustada a su alcance.</td>
                        </tr>
                        <tr>
                            <th scope="row">WordPress + WooCommerce</th>
                            <td>CMS de contenido con WooCommerce como extensión de comercio; amplia personalización.</td>
                            <td>Intermedia: requiere configurar tema, extensiones y tienda.</td>
                            <td>El propietario o proveedor gestiona alojamiento, copias, actualizaciones y extensiones.</td>
                            <td>Negocios que quieren combinar contenido, páginas y una tienda flexible.</td>
                        </tr>
                        <tr>
                            <th scope="row">Shopify</th>
                            <td>Servicio de comercio alojado; varias capacidades dependen del plan y las aplicaciones.</td>
                            <td>Sencilla para empezar con una tienda estándar.</td>
                            <td>El proveedor gestiona la plataforma base; el negocio administra catálogo, aplicaciones y configuración.</td>
                            <td>Equipos que priorizan lanzar y operar una tienda con poca gestión de infraestructura.</td>
                        </tr>
                        <tr>
                            <th scope="row">PrestaShop</th>
                            <td>Plataforma de comercio de código abierto, configurable con módulos.</td>
                            <td>Intermedia: necesita configuración técnica y comercial.</td>
                            <td>Requiere gestionar alojamiento, módulos, actualizaciones y seguridad.</td>
                            <td>Tiendas que necesitan control y cuentan con apoyo para su mantenimiento.</td>
                        </tr>
                        <tr>
                            <th scope="row">Magento Open Source / Adobe Commerce</th>
                            <td>Plataforma de comercio extensible; Adobe Commerce ofrece opciones comerciales adicionales.</td>
                            <td>Avanzada: suele requerir experiencia técnica especializada.</td>
                            <td>Mayor demanda de infraestructura, integración, seguridad y mantenimiento técnico.</td>
                            <td>Operaciones con catálogos o flujos complejos y presupuesto técnico dedicado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="dp-overview__note">
                DigitalPyme es un servicio de consultoría e implementación, no un CMS propietario.
                La plataforma, los costos recurrentes y las tareas de mantenimiento se validan
                contigo y quedan especificados en la cotización; no todas las opciones aplican
                a todos los proyectos.
            </p>
        </section>
    </div>
</section>
