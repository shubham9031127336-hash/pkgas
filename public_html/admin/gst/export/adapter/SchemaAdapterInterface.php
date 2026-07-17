<?php

interface SchemaAdapterInterface {
    public function adapt(object $dto, string $version = 'v1.0'): array;
}
