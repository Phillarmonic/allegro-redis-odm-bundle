<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * Expiration attribute - set TTL for document
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Expiration
{
    public function __construct(
        public int $ttl = 0
    ) {
    }
}