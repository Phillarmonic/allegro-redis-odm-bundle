<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * RedisJson attribute - stores document as Redis JSON
 * Requires RedisJSON module installed on Redis server
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class RedisJson
{
    public function __construct(
        public string $prefix = ''
    ) {
    }
}