<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * ID attribute - marks a property as the document ID
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Id
{
    public function __construct(
        public string $strategy = 'auto' // auto, manual, none
    ) {
    }
}