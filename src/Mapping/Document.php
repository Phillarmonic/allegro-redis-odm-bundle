<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

/**
 * Document attribute - marks a class as a Redis document
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Document
{
    public function __construct(
        public string $prefix = '',
        public ?string $collection = null,
        public ?string $repository = null
    ) {
    }
}