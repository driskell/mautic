<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Tests\Controller;

use Mautic\CoreBundle\Helper\CsvHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

final class ResultExportFunctionalTest extends AbstractFormResultsExportTestCase
{
    // More than one export batch, so paging runs at least twice
    private const SUBMISSION_COUNT = 1050;

    protected function setUp(): void
    {
        $this->configParams['form_export_in_background'] = false;

        if (in_array($this->name(), ['testExportAboveLimitIsRefusedWithErrorForEveryFormat', 'testExportWithinLimitIsAllowed'], true)) {
            $this->configParams['form_export_limit'] = 3;
        }

        parent::setUp();
    }

    public function testCsvExportContainsEveryResultOnceInOrderAcrossBatches(): void
    {
        $formId = $this->createFormWithSubmissions(self::SUBMISSION_COUNT);

        $this->client->request(Request::METHOD_GET, "/s/forms/results/{$formId}/export/csv");
        $this->assertResponseIsSuccessful();

        $lines = array_values(array_filter(explode("\n", $this->client->getInternalResponse()->getContent())));
        $rows  = array_map(fn (string $line): array => CsvHelper::strGetCsv($line), $lines);

        $this->assertSame(['Submission ID', 'Contact ID', 'Date Submitted', 'IP address', 'Referrer', 'Full name', 'Colour'], array_shift($rows));
        $this->assertCount(self::SUBMISSION_COUNT, $rows);

        // Equal submission dates fall back to submission id, in the same direction as the default date sort
        $ids = array_map(fn (array $row): int => (int) $row[0], $rows);
        $this->assertSame($this->getSubmissionIds($formId, 'DESC'), $ids);

        $byName = array_column($rows, null, 5);
        $this->assertSame('blue', $byName['Name 1'][6]);
        $this->assertSame('https://example.com/1', $byName['Name 1'][4]);
        $this->assertArrayHasKey('Tom &amp; Jerry', $byName, 'Stored entities are decoded once');
        $this->assertArrayHasKey('Café', $byName, 'Named entities are decoded');
        $this->assertArrayHasKey(' =SUM(A1)', $byName, 'Formula-like values are prefixed against CSV injection');
    }

    public function testHtmlExportListsHeaderAndEveryResult(): void
    {
        $formId = $this->createFormWithSubmissions(3);

        $crawler = $this->client->request(Request::METHOD_GET, "/s/forms/results/{$formId}/export/html");
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            ['Submission ID', 'Contact ID', 'Date Submitted', 'IP address', 'Referrer', 'Full name', 'Colour'],
            $crawler->filter('table thead th')->each(fn ($th): string => trim($th->text()))
        );
        $this->assertCount(3, $crawler->filter('table tbody tr'));
        $this->assertStringContainsString('Café', $crawler->filter('table tbody')->text());
    }

    #[DataProvider('exportFormatProvider')]
    public function testExportAboveLimitIsRefusedWithErrorForEveryFormat(string $format): void
    {
        $formId = $this->createFormWithSubmissions(4);

        $this->client->followRedirects(false);
        $this->client->request(Request::METHOD_GET, "/s/forms/results/{$formId}/export/{$format}");

        $this->assertResponseRedirects("/s/forms/results/{$formId}");
        $this->client->followRedirect();
        $this->assertStringContainsString('Export limit exceeded. You are trying to export 4 form results, but the limit is set to 3', (string) $this->client->getResponse()->getContent());
    }

    public function testExportWithinLimitIsAllowed(): void
    {
        $formId = $this->createFormWithSubmissions(3);

        $this->client->request(Request::METHOD_GET, "/s/forms/results/{$formId}/export/csv");

        $this->assertResponseIsSuccessful();
        $this->assertCount(4, array_filter(explode("\n", $this->client->getInternalResponse()->getContent())));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function exportFormatProvider(): iterable
    {
        yield 'csv' => ['csv'];
        yield 'xlsx' => ['xlsx'];
        yield 'html' => ['html'];
    }
}
