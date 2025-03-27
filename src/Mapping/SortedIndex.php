<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * SortedIndex attribute - create a sorted index on a numeric field
 * Uses Redis Sorted Sets for efficient range queries
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class SortedIndex
{
    /**
     * @param string $name Index name (defaults to field name if empty)
     * @param int $ttl TTL in seconds for index entries (0 = no expiration)
     */
    public function __construct(
        public string $name = '',
        public int $ttl = 0
    ) {
    }
}