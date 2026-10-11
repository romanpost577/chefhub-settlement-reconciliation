<?php

declare(strict_types=1);

namespace App\Controller;

use App\Reconciliation\Model\DiscrepancyType;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReconciliationReportController extends AbstractController
{
    private const int MAX_ROWS = 200;
    private const int MAX_CHEFS = 50;

    public function __construct(
        #[Autowire(param: 'app.reconciliation.latest_report')]
        private readonly string $reportPath,
    ) {
    }

    #[Route('/reconciliation', name: 'reconciliation_report', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $report = $this->loadReport();
        if ($report === null) {
            return $this->render('reconciliation/report.html.twig', [
                'report' => null,
            ]);
        }
        $activeType = DiscrepancyType::tryFrom($request->query->getString('type'));
        $rows = is_array($report['discrepancies'] ?? null) ? $report['discrepancies'] : [];
        if ($activeType !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (mixed $row): bool => is_array($row) && ($row['type'] ?? null) === $activeType->value,
            ));
        }
        $chefPayouts = is_array($report['chef_payouts'] ?? null) ? $report['chef_payouts'] : [];

        return $this->render('reconciliation/report.html.twig', [
            'report' => $report,
            'activeType' => $activeType?->value,
            'discrepancies' => array_slice($rows, 0, self::MAX_ROWS),
            'discrepancyTotal' => count($rows),
            'chefPayouts' => array_slice($chefPayouts, 0, self::MAX_CHEFS),
        ]);
    }

    /**
     * @return array<mixed>|null
     */
    private function loadReport(): ?array
    {
        if (!is_file($this->reportPath)) {
            return null;
        }

        try {
            $report = json_decode((string)file_get_contents($this->reportPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($report) ? $report : null;
    }
}
