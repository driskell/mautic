<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\UserBundle\Entity\User;

class FormExportScheduler
{
    private ?int $id = null;

    private ?User $user = null;

    private ?Form $form = null;

    private ?\DateTimeImmutable $scheduledDateTime = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $data = [];

    /**
     * @var array<string, mixed>
     */
    private array $changes = [];

    /**
     * @template T of ClassMetadata
     *
     * @param T $metadata
     */
    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('form_export_scheduler');
        $builder->setCustomRepositoryClass(FormExportSchedulerRepository::class);
        $builder->addId();
        $builder->createManyToOne('user', User::class)
            ->addJoinColumn('user_id', 'id', false, false, 'CASCADE')
            ->build();
        $builder->createManyToOne('form', Form::class)
            ->addJoinColumn('form_id', 'id', false, false, 'CASCADE')
            ->build();
        $builder->createField('scheduledDateTime', Types::DATETIME_IMMUTABLE)
            ->columnName('scheduled_datetime')
            ->build();
        $builder->addField('data', Types::JSON);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        $this->changes['user'] = $user->getId();

        return $this;
    }

    public function getForm(): ?Form
    {
        return $this->form;
    }

    public function setForm(Form $form): self
    {
        $this->form = $form;
        $this->changes['form'] = $form->getId();

        return $this;
    }

    public function getScheduledDateTime(): ?\DateTimeImmutable
    {
        return $this->scheduledDateTime;
    }

    public function setScheduledDateTime(\DateTimeImmutable $scheduledDateTime): self
    {
        $this->scheduledDateTime = $scheduledDateTime;
        $this->changes['scheduledDateTime'] = $scheduledDateTime;

        return $this;
    }

    /**
     * Results query arguments captured when the export was requested: filter, orderBy and orderByDir.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data ?? [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        $this->changes['data'] = $data;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }
}
