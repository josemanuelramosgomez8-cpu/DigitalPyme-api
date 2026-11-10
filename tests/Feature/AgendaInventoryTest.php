<?php

namespace Tests\Feature;

use App\Mail\AgendaReservada;
use App\Models\AgendaReservation;
use App\Models\AgendaSlot;
use App\Models\Cotizacion;
use App\Models\Lead;
use App\Models\ProjectMonthlyCapacity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AgendaInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
    }

    public function test_public_schedule_lists_available_weekday_slots_and_booking_consumes_capacity(): void
    {
        Mail::fake();
        $date = $this->nextWeekday();
        $month = $date->format('Y-m');

        $response = $this->getJson("/api/agenda/horarios?month={$month}")
            ->assertOk()
            ->assertJsonPath('month', $month);

        $slot = collect($response->json('slots'))->firstWhere('date', $date->format('Y-m-d'));
        $this->assertNotNull($slot);
        $this->assertSame(1, $slot['available']);

        $this->postJson('/api/agenda/reservas', [
            'agenda_slot_id' => $slot['id'],
            'nombre' => 'María Pérez',
            'email' => 'maria@example.com',
        ])->assertCreated()
            ->assertJsonPath('email_enviado', true);

        $this->assertDatabaseHas('agenda_slots', [
            'id' => $slot['id'],
            'available' => 0,
        ]);
        $this->assertDatabaseHas('agenda_reservations', [
            'agenda_slot_id' => $slot['id'],
            'email' => 'maria@example.com',
            'estado' => 'reservada',
        ]);
        Mail::assertSent(AgendaReservada::class);

        $this->postJson('/api/agenda/reservas', [
            'agenda_slot_id' => $slot['id'],
            'nombre' => 'Otra persona',
            'telefono' => '987654321',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['agenda_slot_id']);
    }

    public function test_admin_can_cancel_a_reservation_and_restore_the_slot(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $slot = AgendaSlot::create([
            'date' => $this->nextWeekday()->format('Y-m-d'),
            'time' => '10:30',
            'capacity' => 1,
            'available' => 0,
            'active' => true,
        ]);
        $reservation = AgendaReservation::create([
            'agenda_slot_id' => $slot->id,
            'nombre' => 'Cliente',
            'email' => 'cliente@example.com',
        ]);

        $this->deleteJson("/api/admin/agenda/reservas/{$reservation->id}")->assertUnauthorized();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/agenda/reservas/{$reservation->id}")
            ->assertOk();

        $this->assertDatabaseHas('agenda_reservations', [
            'id' => $reservation->id,
            'estado' => 'cancelada',
        ]);
        $this->assertDatabaseHas('agenda_slots', [
            'id' => $slot->id,
            'available' => 1,
        ]);
    }

    public function test_admin_can_manage_monthly_project_capacity_and_is_alerted_at_two_slots(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $month = now()->format('Y-m');

        $this->actingAs($admin)
            ->getJson("/api/admin/inventario/proyectos?month={$month}")
            ->assertOk()
            ->assertJsonPath('capacity', 10)
            ->assertJsonPath('available', 10)
            ->assertJsonPath('alerta_pocos_cupos', false);

        $this->putJson('/api/admin/inventario/proyectos', [
            'month' => $month,
            'capacity' => 2,
        ])->assertOk()
            ->assertJsonPath('available', 2)
            ->assertJsonPath('alerta_pocos_cupos', true);
    }

    public function test_accepting_quotes_reserves_project_capacity_and_blocks_projects_when_full(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $month = now()->startOfMonth()->toDateString();
        ProjectMonthlyCapacity::create([
            'month' => $month,
            'capacity' => 1,
            'reserved' => 0,
        ]);

        $lead = $this->createLead('uno@example.com');
        $quote = $this->createQuote($lead);
        $this->actingAs($admin)
            ->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertOk();

        $this->assertDatabaseHas('project_monthly_capacities', [
            'month' => $month,
            'capacity' => 1,
            'reserved' => 1,
        ]);
        $this->assertDatabaseHas('projects', ['cotizacion_id' => $quote->id]);

        $secondQuote = $this->createQuote($this->createLead('dos@example.com'));
        $this->patchJson("/api/cotizaciones/{$secondQuote->id}", ['estado' => 'aceptada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacidad']);

        $this->assertDatabaseMissing('projects', ['cotizacion_id' => $secondQuote->id]);
        $this->assertDatabaseHas('cotizaciones', ['id' => $secondQuote->id, 'estado' => 'borrador']);
    }

    private function nextWeekday(): CarbonImmutable
    {
        $date = CarbonImmutable::tomorrow();
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }

    private function createLead(string $email): Lead
    {
        return Lead::create([
            'nombre' => 'Cliente de prueba',
            'email' => $email,
            'servicio' => 'Página web',
        ]);
    }

    private function createQuote(Lead $lead): Cotizacion
    {
        return $lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'descripcion' => 'Diseño y desarrollo del sitio web.',
            'monto' => 1800,
        ]);
    }
}
