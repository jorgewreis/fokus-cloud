<?php

namespace Tests\Feature;

use App\Models\LawBetaApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LawBetaApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [...[
            'name' => 'Ana Advogada',
            'email' => 'ana@example.test',
            'organization' => 'Escritório Ana',
            'feedback' => 'Quero testar a organização de contatos e o acompanhamento de processos.',
            'privacy_accepted' => true,
            'privacy_version' => '2.0',
            'beta_terms_accepted' => true,
            'beta_program_version' => '1.0',
            'source_url' => 'https://fokus.test/produtos/fokus-law#interesse',
        ], ...$overrides];
    }

    public function test_public_beta_application_is_created_without_commercial_side_effects(): void
    {
        Mail::fake();

        $this->postJson('/api/law/beta-applications', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Candidatura ao beta registrada com sucesso.');

        $application = LawBetaApplication::sole();
        $this->assertSame('law_advocacia_beta_6m', $application->campaign_code);
        $this->assertSame('Advocacia', $application->profile);
        $this->assertSame(['Gestão de Contatos', 'Gestão de Processos'], $application->modules);
        $this->assertSame('novo', $application->status);
        $this->assertNotNull($application->privacy_consented_at);
        $this->assertNotNull($application->beta_terms_accepted_at);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_same_email_updates_only_its_application_in_the_same_campaign(): void
    {
        $this->postJson('/api/law/beta-applications', $this->payload())->assertCreated();
        $id = LawBetaApplication::sole()->id;

        $this->postJson('/api/law/beta-applications', $this->payload([
            'name' => 'Ana Atualizada',
            'feedback' => 'Quero revisar os vínculos entre contatos e processos.',
        ]))->assertOk()
            ->assertJsonPath('application_id', $id);

        $this->assertDatabaseCount('law_beta_applications', 1);
        $this->assertDatabaseHas('law_beta_applications', [
            'id' => $id,
            'name' => 'Ana Atualizada',
            'status' => 'novo',
        ]);
    }

    public function test_beta_application_requires_both_consents(): void
    {
        $this->postJson('/api/law/beta-applications', $this->payload(['privacy_accepted' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('privacy_accepted');

        $this->postJson('/api/law/beta-applications', $this->payload(['beta_terms_accepted' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beta_terms_accepted');

        $this->assertDatabaseCount('law_beta_applications', 0);
    }

    public function test_beta_application_rejects_honeypot_without_persisting(): void
    {
        $this->postJson('/api/law/beta-applications', $this->payload(['website' => 'https://spam.example.test']))
            ->assertUnprocessable();

        $this->assertDatabaseCount('law_beta_applications', 0);
    }
}
