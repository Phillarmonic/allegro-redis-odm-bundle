<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * Index attribute - create secondary index on field
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Index
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