<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

use DateTimeInterface;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Field;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\SortedIndex;

/**
 * Trait to add automatic created_at and updated_at timestamps to a document.
 */
trait TimestampableTrait
{
    #[Field(name: '_created_at', type: 'datetime', nullable: true)]
//    #[SortedIndex(name: 'created_at_idx')]
    protected ?DateTimeInterface $createdAt = null;

    #[Field(name: '_updated_at', type: 'datetime', nullable: true)]
//    #[SortedIndex(name: 'updated_at_idx')]
    protected ?DateTimeInterface $updatedAt = null;

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}