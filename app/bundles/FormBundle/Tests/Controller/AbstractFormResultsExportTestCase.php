<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Tests\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates forms whose results export as: Submission ID, Contact ID, Date Submitted, IP address, Referrer, Full name, Colour.
 */
abstract class AbstractFormResultsExportTestCase extends MauticMysqlTestCase
{
    // Creating a form creates its results table, which commits the transaction
    protected $useCleanupRollback = false;

    private static string $dateSubmitted = '2024-01-02 03:04:05';

    protected function createFormWithSubmissions(int $count): int
    {
        $this->client->request(Request::METHOD_POST, '/api/forms/new', [
            'name'        => 'Export form',
            'formType'    => 'standalone',
            'alias'       => 'exportform',
            'isPublished' => true,
            'postAction'  => 'return',
            'fields'      => [
                ['label' => 'Full name', 'alias' => 'full_name', 'type' => 'text'],
                ['label' => 'Intro', 'alias' => 'intro', 'type' => 'freetext'],
                [
                    'label'      => 'Colour',
                    'alias'      => 'colour',
                    'type'       => 'select',
                    'properties' => ['list' => ['list' => [['label' => 'Blue', 'value' => 'blue']]]],
                ],
            ],
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $form = json_decode($this->client->getResponse()->getContent(), true)['form'];

        $names = [1 => 'Name 1', 2 => 'Tom &amp;amp; Jerry', 3 => 'Caf&eacute;', 4 => '=SUM(A1)'];

        $connection = $this->em->getConnection();
        for ($i = 1; $i <= $count; ++$i) {
            $connection->insert(MAUTIC_TABLE_PREFIX.'form_submissions', [
                'form_id'        => $form['id'],
                'date_submitted' => self::$dateSubmitted,
                'referer'        => 'https://example.com/'.$i,
            ]);
            $connection->insert(MAUTIC_TABLE_PREFIX.'form_results_'.$form['id'].'_'.$form['alias'], [
                'submission_id' => $connection->lastInsertId(),
                'form_id'       => $form['id'],
                'full_name'     => $names[$i] ?? 'Name '.$i,
                'colour'        => 'blue',
            ]);
        }

        return (int) $form['id'];
    }

    /**
     * @return int[]
     */
    protected function getSubmissionIds(int $formId, string $direction): array
    {
        return array_map(intval(...), $this->em->getConnection()->createQueryBuilder()
            ->select('s.id')
            ->from(MAUTIC_TABLE_PREFIX.'form_submissions', 's')
            ->where('s.form_id = :formId')
            ->setParameter('formId', $formId)
            ->orderBy('s.id', $direction)
            ->executeQuery()
            ->fetchFirstColumn());
    }
}
