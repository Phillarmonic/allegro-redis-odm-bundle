<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * Index attribute - create secondary index on field
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Index
{
    public function __construct(
        public string $name = ''
    ) {
    }
}