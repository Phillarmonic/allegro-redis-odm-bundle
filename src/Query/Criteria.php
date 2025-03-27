<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Query;

use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

/**
 * Criteria for building queries with a fluent interface
 */
class Criteria
{
    /**
     * @var array Filter criteria
     */
    private array $criteria = [];

    /**
     * @var array|null Ordering criteria
     */
    private ?array $orderBy = null;

    /**
     * @var int|null Maximum number of results
     */
    private ?int $limit = null;

    /**
     * @var int|null Offset for pagination
     */
    private ?int $offset = null;

    /**
     * Create a new Criteria instance
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a field criteria
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @return self
     */
    public function andWhere(string $field, $value): self
    {
        $this->criteria[$field] = $value;
        return $this;
    }

    /**
     * Add ordering
     *
     * @param string $field Field name
     * @param string $direction 'ASC' or 'DESC'
     * @return self
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->orderBy = $this->orderBy ?? [];
        $this->orderBy[$field] = $direction;
        return $this;
    }

    /**
     * Set maximum number of results
     *
     * @param int $limit
     * @return self
     */
    public function setMaxResults(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the first result offset
     *
     * @param int $offset
     * @return self
     */
    public function setFirstResult(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Set page for pagination
     *
     * @param int $page Page number (1-based)
     * @param int $itemsPerPage Items per page
     * @return self
     */
    public function setPage(int $page, int $itemsPerPage): self
    {
        if ($page < 1) {
            $page = 1;
        }

        $this->limit = $itemsPerPage;
        $this->offset = ($page - 1) * $itemsPerPage;

        return $this;
    }

    /**
     * Get the criteria array
     *
     * @return array
     */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Get the orderBy array
     *
     * @return array|null
     */
    public function getOrderBy(): ?array
    {
        return $this->orderBy;
    }

    /**
     * Get the limit
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get the offset
     *
     * @return int|null
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Execute the query against a repository
     *
     * @param DocumentRepository $repository
     * @return PaginatedResult
     */
    public function execute(DocumentRepository $repository): PaginatedResult
    {
        return $repository->findBy(
            $this->criteria,
            $this->orderBy,
            $this->limit,
            $this->offset
        );
    }
}