<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\CurrencyRateSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCurrencyRatesCommandTest extends TestCase
{
    public function test_sync_stores_frankfurter_rates_and_effective_update_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 14:00:05', 'America/Sao_Paulo'));
        try {
            Setting::set('currencies', [
                ['code' => 'BRL', 'symbol' => 'R$', 'label' => 'Real brasileiro', 'rate_to_brl' => 1],
                ['code' => 'USD', 'symbol' => 'US$', 'label' => 'Dólar americano', 'rate_to_brl' => 0.18],
                ['code' => 'EUR', 'symbol' => '€', 'label' => 'Euro', 'rate_to_brl' => 0.16],
            ], null);

            Http::fake([
                'https://api.frankfurter.dev/v1/latest*' => Http::response([
                    'amount' => 1,
                    'base' => 'BRL',
                    'date' => '2026-10-08',
                    'rates' => ['USD' => 0.19933, 'EUR' => 0.1782],
                ]),
            ]);

            $this->artisan('currencies:sync-rates')
                ->expectsOutputToContain('Última atualização efetiva: 08/10/2026 às 14:00')
                ->assertSuccessful();

            $stored = json_decode((string) Setting::get('currencies', '[]', null), true);
            $byCode = collect($stored)->keyBy('code');
            $this->assertEqualsWithDelta(1.0, (float) $byCode['BRL']['rate_to_brl'], 0.000001);
            $this->assertEqualsWithDelta(0.19933, (float) $byCode['USD']['rate_to_brl'], 0.000001);
            $this->assertEqualsWithDelta(0.1782, (float) $byCode['EUR']['rate_to_brl'], 0.000001);
            $this->assertSame('Dólar americano', $byCode['USD']['label']);
            $this->assertSame('08/10/2026 às 14:00', CurrencyRateSyncService::updatedAtLabel());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_lookup_keeps_the_previous_effective_update(): void
    {
        Setting::set('currencies', [
            ['code' => 'USD', 'symbol' => 'US$', 'label' => 'Dólar americano', 'rate_to_brl' => 0.18],
        ], null);
        Setting::set(CurrencyRateSyncService::UPDATED_AT_KEY, '2026-10-07T14:00:00-03:00', null);

        Http::fake([
            'https://api.frankfurter.dev/*' => Http::response('unavailable', 503),
        ]);

        $this->artisan('currencies:sync-rates')->assertFailed();

        $stored = json_decode((string) Setting::get('currencies', '[]', null), true);
        $this->assertEqualsWithDelta(0.18, (float) $stored[0]['rate_to_brl'], 0.000001);
        $this->assertSame('07/10/2026 às 14:00', CurrencyRateSyncService::updatedAtLabel());
    }

    public function test_currency_sync_is_scheduled_on_weekdays_at_14_brasilia(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'currencies:sync-rates'));

        $this->assertNotNull($event);
        $this->assertSame('0 14 * * 1-5', $event->expression);
        $this->assertSame('America/Sao_Paulo', $event->timezone);
    }
}
