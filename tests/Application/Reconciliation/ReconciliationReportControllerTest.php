<?php

declare(strict_types=1);

namespace App\Tests\Application\Reconciliation;

use App\Tests\ApplicationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ReconciliationReportControllerTest extends ApplicationTestCase
{
    private KernelBrowser $client;

    private string $reportPath;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->reportPath = (string)static::getContainer()->getParameter('app.reconciliation.latest_report');
        $path = $this->reportPath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->reportPath)) {
            unlink($this->reportPath);
        }
        parent::tearDown();
    }

    public function testShowsHintWhenNoReportExists(): void
    {
        $this->client->request('GET', '/reconciliation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No report found');
        self::assertSelectorTextContains('body', 'app:reconcile');
    }

    public function testRendersReport(): void
    {
        $this->writeReport();

        $this->client->request('GET', '/reconciliation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'ChefHub settlement reconciliation');
        $body = (string)$this->client->getResponse()->getContent();
        self::assertStringContainsString('CH-000002', $body);
        self::assertStringContainsString('CH-000008', $body);
        self::assertStringContainsString('CF-0001', $body);
        self::assertStringContainsString('processor_a_settlement.csv row 5', $body);
    }

    public function testFiltersByType(): void
    {
        $this->writeReport();

        $this->client->request('GET', '/reconciliation?type=amount_mismatch');

        self::assertResponseIsSuccessful();
        $table = $this->client->getCrawler()->filter('#discrepancies')->text();
        self::assertStringContainsString('CH-000002', $table);
        self::assertStringNotContainsString('CH-000008', $table);
    }

    public function testUnknownTypeFilterShowsAll(): void
    {
        $this->writeReport();

        $this->client->request('GET', '/reconciliation?type=garbage');

        self::assertResponseIsSuccessful();
        $table = $this->client->getCrawler()->filter('#discrepancies')->text();
        self::assertStringContainsString('CH-000002', $table);
        self::assertStringContainsString('CH-000008', $table);
    }

    private function writeReport(): void
    {
        $row = static fn (string $type, string $orderId, float $usd): array => [
            'type' => $type,
            'priority' => 'low',
            'order_id' => $orderId,
            'settlement_source' => 'processor_a',
            'settlement_id' => 'PA-1',
            'customer_id' => 'CU-1',
            'chef_id' => 'CF-0001',
            'currency' => 'PHP',
            'order_amount' => '180.50',
            'settled_amount' => '190.50',
            'impact' => '10.00',
            'impact_usd' => $usd,
            'match_kind' => 'exact',
            'note' => 'note',
        ];
        $typeSummary = [
            'count' => 1,
            'exposure_by_currency' => [
                'PHP' => '10.00',
            ],
            'exposure_usd' => 0.17,
        ];
        $report = [
            'meta' => [
                'generated_at' => '2026-10-10T12:00:00+00:00',
                'duration_ms' => 12,
            ],
            'summary' => [
                'internal_orders' => 8,
                'settlements' => [
                    'processor_a' => 3,
                ],
                'matched_exact' => 3,
                'matched_fuzzy' => 1,
                'total_discrepancies' => 2,
                'total_exposure_usd' => 1.46,
                'by_type' => [
                    'amount_mismatch' => $typeSummary,
                    'missing_in_settlement' => $typeSummary,
                ],
            ],
            'insights' => [],
            'chef_payouts' => [[
                'chef_id' => 'CF-0001',
                'currency' => 'PHP',
                'settled_gross' => '250.00',
                'commission' => '37.50',
                'payable_now' => '212.50',
                'on_hold' => '255.50',
                'discrepancy_count' => 2,
            ]],
            'discrepancies' => [
                $row('missing_in_settlement', 'CH-000008', 1.29),
                $row('amount_mismatch', 'CH-000002', 0.17),
            ],
            'warnings' => ['processor_a_settlement.csv row 5: duplicate row PA-00000003'],
        ];
        file_put_contents($this->reportPath, json_encode($report, JSON_THROW_ON_ERROR));
    }
}
