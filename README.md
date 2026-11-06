<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Administrator account

After configuring the database and running migrations, create or update the administrator account with:

```bash
php artisan admin:password admin@example.com
```

The command prompts for the account name and a new password without displaying the password. Use an email address you control. Administrator accounts are marked locally in the database; ordinary users cannot access admin routes. Running the database seeder no longer creates or resets a default administrator password.

For local development only, `php artisan client:password:local-test` assigns the shared test password `password` to accounts that have an associated client-portal profile and revokes their existing API tokens. The command refuses to run unless `APP_ENV=local`. Never use the shared test password for real customers or in production.

Open the separate administration interface at `http://localhost:8443/?admin=1` and sign in with the administrator account. The panel includes operational metrics, lead and quote management, project progress, agenda slots and reservations, transfer receipt review, and CRUD for services, portfolio projects, and testimonials. It reuses the existing API and styling without adding an admin link to the public navigation.

The portfolio includes DigitalPyme's own services catalog as a factual project entry, with no customer result claim, plus three clearly labeled visual sample concepts that are not client projects. The sample concepts use locally stored illustrative photographs from [Unsplash](https://unsplash.com/license); the image alt text identifies them as illustrations. Their detail fields are editable in the admin panel. Apply schema updates with `php artisan migrate`; running `php artisan db:seed` adds missing project details and updates only the sample projects' original illustration assets without overwriting details or images customized by an administrator. No customer testimonials are seeded: the website shows clearly labeled fictional layout examples only while there are no approved client reviews. Reviews submitted from the site remain private until approved in the admin panel. Once approved, the public page refreshes its review list when the visitor returns to the tab, and **Actualizar reseñas** or **Comprobar aprobación** can be used to check manually. The public review feed at `GET /api/resenas` supports `offset` and `limit` query parameters for loading additional approved reviews; unverified sample entries from earlier seed data are deactivated by migration.

The public WhatsApp contact is intentionally unset by default. Configure it under **SEO y tema → Datos de contacto** with an international number such as `+51...`; the site and proposal notifications use that value. Until configured, no WhatsApp contact number or link is displayed.

The privacy-policy field is also managed under **SEO y tema**. A starter draft based on the site's current forms and account features is provided but is unpublished by default. The owner must verify the legal identity, actual providers, retention periods, and applicable requirements before enabling the public footer link.

### Public quote registration and verification

The public quote configurator requires a commercial contact form with name, surname, company, country, WhatsApp number, email, and explicit acceptance of the quote terms and data-use purpose. The email must be verified with a six-digit code before the proposal can be created. Codes expire after 10 minutes, allow at most five verification attempts, and are protected by request throttles, per-address cooldowns, and a honeypot field. Verification tokens are short-lived, bound to the email and requester IP, and consumed once when the quote is submitted. The WhatsApp number is required for follow-up but is not SMS-verified; no SMS provider is configured.

The verification code is delivered through the configured Laravel mailer. Configure and test a production SMTP/mail provider before enabling real quote requests; `MAIL_MAILER=log` is for local development and does not deliver email. The privacy notice is still unpublished by default, so the site owner should review and publish the privacy policy before collecting real customer details.

In Panel administrador → Solicitudes, keep a request in review while confirming its scope and price. Mark a priced quote as “Enviada” to email the customer a PDF summary; a draft with a zero amount cannot be sent or accepted. Only an accepted quote creates a project and enables its payment methods. Confirmed project payments produce an informational PDF receipt, available in the client portal and sent by email when the mailer is configured. This receipt is not a SUNAT electronic invoice or tax receipt.

The final quote-request step records an optional preferred payment method (card, bank transfer, Yape/Plin, or undecided). It is saved with the request and visible in **Solicitudes**; it does not initiate a charge. To test payment operation, issue and accept the final priced quote, then start the corresponding payment from the client's project portal. Card payments use Mercado Pago Sandbox when `MERCADOPAGO_ENVIRONMENT=sandbox`; manual methods require their payment instructions and receipt review to be configured.

### Local email notifications

The example environment uses the `log` mailer, which does not deliver messages. For low-volume local testing with Gmail, enable 2-Step Verification on the mailbox, create a Google App Password, and keep it only in the ignored backend `.env` file:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD=your-google-app-password
MAIL_FROM_ADDRESS=your-address@gmail.com
MAIL_FROM_NAME=DigitalPyme
SOLICITUDES_EMAIL=your-address@gmail.com
```

Never commit or share the App Password. Gmail is a temporary option for local, low-volume testing; configure and test a production mail provider after selecting the production host and domain.

DigitalPyme's public site includes a services catalog and a physical-product storefront at `/tienda`. The store requires a client account to place an order. Existing store orders and their admin history are retained.

Store checkout collects customer contact and delivery details, DNI/RUC, and the requested boleta or factura. Product catalog prices are before tax; checkout calculates 18% IGV on products after discounts plus shipping, and returns the itemized amounts in the order. Admins manage percentage or fixed-amount coupons in **Logística**; percentage discounts cannot exceed 100%. The order email includes an informational PDF summary, not an electronic invoice or SUNAT receipt. A second email is sent when an administrator verifies a manual payment or Mercado Pago confirms a card payment.

Run `php artisan migrate` to add checkout details and coupons. Mercado Pago is configured for `sandbox` by default; set `MERCADOPAGO_ACCESS_TOKEN`, the webhook URL, and the return URL in the ignored `.env` before testing. Keep sandbox credentials for test transactions and switch `MERCADOPAGO_ENVIRONMENT` to `production` only with the business's live credentials and verified payment destination.

The service catalog includes approved scope descriptions for web design, online stores, branding, digital marketing, and maintenance. Prices, timelines, campaign channels, revision counts, and other project-specific conditions remain subject to the proposal; do not treat them as included unless the approved scope says so.

The protected dashboard metrics endpoint is `GET /api/admin/dashboard?month=YYYY-MM`. It reports lead and quote counts, project status totals, payments awaiting verification, confirmed payment totals, reservations in the selected month, and configured monthly project capacity.

The admin store endpoint `GET /api/admin/store` paginates products, stock movements, orders, and returns independently while keeping each collection as an array. It returns pagination metadata under `pagination`; use `per_page` (1-100, default 50) and the corresponding `products_page`, `movements_page`, `orders_page`, or `returns_page` query parameter to navigate each collection. For example, `GET /api/admin/store?per_page=25&orders_page=2&returns_page=3`.

Store products support an optional category and a JPG, PNG, or WebP image up to 5 MB. Administrators upload or remove images from Panel administrador → Logística; files stay on the private `uploads` disk and storefront images are streamed through a public API route without exposing storage paths. The two seeded demonstration products are inactive and are not offered for sale; activate a product only after confirming its real price and inventory.

The logistics panel records stock receipts and adjustments, reorder thresholds, warehouse locations, monthly paid-unit turnover, and the five fastest-moving active products to help prioritize picking locations. Order fulfillment advances through picking, packing, shipment, and delivery; admins can select in-house or 3PL operation and record a carrier and tracking code. Return requests move through approval, receipt, documented inspection, and a resolution (resale, repair, disposal, or refund); resale adds returned units to inventory only after resolution. Shipping methods, delivery estimates, zone fees, and free-shipping threshold remain configurable under **SEO y tema**.

### SEO, brand, and coverage settings

Apply the settings table with `php artisan migrate`. Public SEO and theme configuration is available at `GET /api/site-settings`; administrators update it with `PUT /api/admin/site-settings`. The admin panel's **SEO y tema** tab edits the SEO title and description, friendly slug, Open Graph image, brand logo, color palette, typography, digital service modality, and configurable online-store shipping zones.

The starting theme defaults to the existing dark green (`#102c2c`), lime (`#c9f45b`), cream (`#f6f4ed`), DM Sans, and DM Serif Display. Supported font names are selected from a fixed allowlist. `GET /api/cobertura?servicio=Tiendas%20en%20linea&ubicacion=Lima` returns a configured shipping fee and delivery estimate; other digital services return whether they are virtual or also available in person in Lima. For store checkout, a configured zone fee is the standard-shipping base; express and same-day add only the difference between their configured method price and the standard price. If no zone matches, the configured method price is used. Add only real, verified shipping locations and prices in the panel.

Laravel serves `/sitemap.xml` and `/robots.txt`; set `FRONTEND_URL` to the deployed frontend origin so sitemap links use the public site. If frontend and backend use different hosts, configure the frontend web server to proxy `/sitemap.xml` and `/robots.txt` to Laravel, so crawlers find them on the public site host. An editable slug is used as the canonical URL and sitemap entry. Configure the frontend host to rewrite that slug path to the SPA's `index.html` (Vite's development server already falls back to the SPA). Portfolio images already have editable alt text and a generated fallback description when the field is empty.

## Clients and projects

Administrators can convert a lead into a client with `POST /api/leads/{lead}/client`; the lead must have an email address (or an `email` can be supplied in the request). A random password is created and never returned. Set the client's initial password on the server with:

```bash
php artisan client:password client@example.com
```

Clients sign in at `POST /api/client/login` and can only read their own projects at `GET /api/client/projects` and `GET /api/client/projects/{project}`. They can change their password with `PUT /api/client/password`, providing `current_password`, `password`, and `password_confirmation`.

Accepting a quote creates its client account if needed and creates one project for that quote. The client needs an email address before the quote can be accepted. Administrators can list, create, and update projects at `/api/admin/projects`; valid project states are `planificacion`, `en_progreso`, `revision`, `completado`, and `pausado`.

### Project documents

Clients can list and upload documents only for their own projects. Administrators can list, upload, download, and delete documents for any project. Uploads accept PDF, JPG/JPEG, PNG, DOC/DOCX, and XLS/XLSX files up to 10 MB. Documents are stored on Laravel's private `uploads` disk and downloaded through authenticated routes; the storage path is not returned by the API.

Project documents, payment receipts, and product images use the `uploads` disk. By default it keeps the existing private local-storage location. For Docker Compose, `compose.yaml` mounts the named `digitalpyme-uploads` volume at that location and runs a separate scheduler service. Configure the database and application secrets in the ignored `.env` file, then run `docker compose run --rm app php artisan migrate --force` followed by `docker compose up -d --build`. For another hosting platform, mount its persistent disk and set `UPLOADS_PATH` to the mount path (for example, `/var/data/digitalpyme-uploads` when the disk is mounted at `/var/data`); the web-server user must be able to write there. Before changing the path on an existing deployment, copy the existing `storage/app/private` contents into the mounted directory because database records retain relative file paths.

Back up the upload volume and database from a consistent point in time, keep copies off-host, and periodically test a restore. For the Compose volume, create a timestamped local archive with `docker run --rm -v digitalpyme-uploads:/data:ro -v "${PWD}/backups:/backup" alpine:3.22 sh -c 'tar -czf "/backup/uploads-$(date -u +%Y%m%dT%H%M%SZ).tar.gz" -C /data .'`; coordinate it with a database backup using the database provider's supported tool, and pause writes if the provider cannot take a consistent snapshot. Transfer both backups to a separate location. This is a manual example, not an automated or off-host backup service.

- Client: `GET` and `POST /api/client/projects/{project}/documents`
- Client download: `GET /api/client/projects/{project}/documents/{document}/download`
- Admin: `GET`, `POST`, and `DELETE /api/admin/projects/{project}/documents[/{document}]`
- Admin download: `GET /api/admin/projects/{project}/documents/{document}/download`

### Project messages

Clients and administrators can exchange plain-text messages in a project-specific conversation. Client routes only expose projects owned by the authenticated client; administrative routes require an administrator account. Messages are limited to 2,000 characters and are returned in chronological order. The client portal has a **Mensajes** section, and administrators can select a project in the admin **Mensajes** tab.

- Client: `GET` and `POST /api/client/projects/{project}/messages`
- Admin: `GET` and `POST /api/admin/projects/{project}/messages`

### Project payments

The client portal records full-project and approved-milestone payments in Peruvian soles. Bank-transfer payments remain pending until the client uploads a PDF/JPG/PNG receipt and an administrator verifies or rejects it. Receipts are stored on the private `uploads` disk and must be downloaded through authenticated project-document routes.

- Client payment options/history: `GET /api/client/projects/{project}/payments`
- Start a transfer: `POST /api/client/projects/{project}/payments/transfers` (optional `milestone_id`)
- Upload transfer receipt: `POST /api/client/projects/{project}/payments/{payment}/receipt` (`multipart/form-data`, `file`)
- Download a confirmed payment's PDF receipt: `GET /api/client/projects/{project}/payments/{payment}/receipt`
- Start hosted card checkout: `POST /api/client/projects/{project}/payments/card` (optional `milestone_id`)
- Mercado Pago notification: `POST /api/payments/mercadopago/webhook`
- Admin review queue: `GET /api/admin/payments?status=comprobante_enviado`
- Admin verify/reject a transfer: `PATCH /api/admin/payments/{payment}` with `{"status":"pagado"}` or `{"status":"rechazado"}`

Card checkout uses Mercado Pago Checkout Pro, which hosts card entry and supports Visa/Mastercard. No card details or provider credentials are stored by this application. To enable it, configure a real `MERCADOPAGO_ACCESS_TOKEN`, a publicly reachable `MERCADOPAGO_WEBHOOK_URL`, and `PAYMENT_RETURN_URL` in the backend `.env`. The webhook verifies each notification against Mercado Pago's Payments API and checks its reference, amount, and currency before marking a payment as paid. Keep the access token server-side and use the provider's test credentials before switching to production credentials. Without the access token, card checkout responds as unavailable; it never simulates a successful payment.

Configure `BANK_TRANSFER_BANK`, `BANK_TRANSFER_HOLDER`, `BANK_TRANSFER_ACCOUNT`, and `BANK_TRANSFER_CCI` to show transfer instructions in the authenticated client portal. Apply schema updates with `php artisan migrate`.

Payment creation is serialized per project to prevent duplicate or over-budget full-project and milestone charges. Only accepted quotes and approved milestones with a configured amount can be paid. The administrator verifies manual receipts in Panel administrador → Pagos. A confirmed project payment generates an informational PDF receipt for authenticated download and sends it by email when SMTP delivery is available.

### Store payments

The store supports hosted card checkout through Mercado Pago plus manual Yape/Plin and bank-transfer payments. Cards appear only when `MERCADOPAGO_ACCESS_TOKEN` is configured. Set `YAPE_PLIN_ENABLED=true` and `YAPE_PLIN_PHONE` (optionally `YAPE_PLIN_HOLDER` and `YAPE_PLIN_QR_URL`) to offer Yape/Plin. Set `BANK_TRANSFER_ACCOUNT` or `BANK_TRANSFER_CCI` to offer a bank transfer; `BANK_TRANSFER_BANK`, `BANK_TRANSFER_HOLDER`, and the account/CCI values are displayed at checkout. Keep these destination details current and reload Laravel's configuration cache after changing production environment variables.

Store orders start as pending payment and reserve stock for 24 hours. Card payments redirect to Mercado Pago Checkout Pro; the webhook independently verifies the provider payment ID, order reference, amount, and currency before confirming an order. Clients upload a PDF, JPG, or PNG proof (up to 10 MB) for manual Yape/Plin and transfers from Portal cliente → Pedidos. An administrator reviews those proofs in Panel administrador → Logística. Only confirmed orders can advance into fulfillment; unpaid orders are cancelled and stock is returned automatically. Uploaded proofs stay on Laravel's private `uploads` disk and are downloaded only through authenticated client/admin routes.

After a payment is confirmed, the client can view and print a payment receipt with the order, line items, date, method, and amount. This receipt is informative and **is not** a SUNAT boleta or factura. For the future SUNAT integration, Nubefact is the recommended provider to evaluate for its electronic-invoicing API. It is not connected yet: first create and validate a Nubefact account, obtain its API credentials privately, and provide the business RUC/tax setup before enabling electronic documents. Never commit or share provider credentials.

The scheduler runs the expiration command every minute. During local development, keep `php artisan schedule:work` running. With Docker Compose, the dedicated `scheduler` service runs this command; check it with `docker compose logs scheduler` and keep exactly one scheduler service active. On another platform, configure one scheduled process to run `php artisan schedule:run` every minute using the same application image, environment, database, and cache. The web Docker image by itself does not start a scheduler process. Verify the task with `php artisan schedule:list` and confirm expired orders release stock after their payment deadline.

Bearer tokens issued to administrators and clients expire after 24 hours by default. Set `SANCTUM_EXPIRATION` to another positive number of minutes if needed; token expiration is absolute from issuance, not renewed by activity. The frontend keeps these tokens only for the current browser tab, so closing the tab requires signing in again.

Public lead submissions are limited to five requests per minute per client IP, matching the existing public proposal and review limits.

## Production readiness

Before launch, replace placeholder URLs with the real HTTPS frontend/API addresses and configure the production environment without committing secrets. At the hosting provider, enable HTTPS and restrictive security headers, including a Content Security Policy that explicitly permits only the real API, payment, and asset origins; the policy must match the selected host and integrations. Multi-factor authentication for administrator accounts is not enabled yet and should be decided before exposing the admin portal publicly.

Also verify persistent uploads, the scheduler, coordinated database/file backups and a restore, email delivery, real payment destination details and provider credentials, and the business's legal/privacy information. Electronic invoices are not enabled; complete the tax-provider and business-registration setup before presenting any receipt as a legal invoice.

- Public payment options: `GET /api/store/payment-options`
- Start store card checkout: `POST /api/client/store/orders/{order}/payments/card`
- Client receipt upload/download: `POST` / `GET /api/client/store/orders/{order}/receipt`
- Admin receipt review/download: `PATCH /api/admin/store/orders/{order}/payment`, `GET /api/admin/store/orders/{order}/receipt`

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
#   D i g i t a l P y m e - a p i  
 