<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * RedisHash attribute - stores document as Redis hash
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class RedisHash
{
    public function __construct(
        public string $prefix = ''
    ) {
    }
}