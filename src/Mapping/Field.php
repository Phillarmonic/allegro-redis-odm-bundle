<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * Field attribute - maps a property to a Redis field
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Field
{
    public function __construct(
        public ?string $name = null,
        public string $type = 'string', // string, integer, float, boolean, datetime, json
        public bool $nullable = false
    ) {
    }
}