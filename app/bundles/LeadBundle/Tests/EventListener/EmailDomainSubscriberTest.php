<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\EventListener;

use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\EventListener\EmailDomainSubscriber;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\ListModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailDomainSubscriberTest extends TestCase
{
    /**
     * @var MockObject&TranslatorInterface
     */
    private \Mautic\CoreBundle\Translation\Translator|MockObject $translator;

    private EmailDomainSubscriber $emailDomainSubscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $modelTranslator = $this->createMock(Translator::class);
        $modelTranslator
            ->method('trans')
            ->willReturnArgument(0);

        $segmentModel = new class($modelTranslator) extends ListModel {
            public function __construct(Translator $translator)
            {
                $this->translator = $translator;
            }
        };

        $this->translator            = $this->createMock(TranslatorInterface::class);
        $this->emailDomainSubscriber = new EmailDomainSubscriber($segmentModel, $this->translator);
    }

    /**
     * The email domain column is no longer a database generated column, so this subscriber must not
     * listen for CoreEvents::ON_GENERATED_COLUMNS_BUILD. An indexed generated column would block
     * ALTER TABLE ... ALGORITHM=INSTANT on the leads table.
     */
    public function testItOnlySubscribesToTheSegmentFilterEvent(): void
    {
        $this->assertSame(
            [LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['onGenerateSegmentFilters', 0]],
            EmailDomainSubscriber::getSubscribedEvents()
        );
    }

    public function testOnGenerateSegmentFilters(): void
    {
        $event = new LeadListFiltersChoicesEvent(
            [],
            [],
            $this->translator,
            new Request()
        );

        $this->translator->method('trans')
            ->with('mautic.email.segment.choice.generated_email_domain')
            ->willReturn('translated string');

        $this->emailDomainSubscriber->onGenerateSegmentFilters($event);

        $this->assertSame([
            'label'      => 'translated string',
            'properties' => ['type' => 'text'],
            'operators'  => [
                'mautic.lead.list.form.operator.equals'     => '=',
                'mautic.lead.list.form.operator.notequals'  => '!=',
                'mautic.lead.list.form.operator.isempty'    => 'empty',
                'mautic.lead.list.form.operator.isnotempty' => '!empty',
                'mautic.lead.list.form.operator.islike'     => 'like',
                'mautic.lead.list.form.operator.isnotlike'  => '!like',
                'mautic.lead.list.form.operator.regexp'     => 'regexp',
                'mautic.lead.list.form.operator.notregexp'  => '!regexp',
                'mautic.core.operator.starts.with'          => 'startsWith',
                'mautic.core.operator.ends.with'            => 'endsWith',
                'mautic.core.operator.contains'             => 'contains',
            ],
            'object'    => 'lead',
            'iconClass' => 'ri-at-line',
        ], $event->getChoices()['lead']['generated_email_domain']);
    }
}
