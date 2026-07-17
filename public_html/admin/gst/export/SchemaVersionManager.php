<?php

require_once __DIR__ . '/adapter/Gstr1SchemaAdapter.php';
require_once __DIR__ . '/adapter/Gstr3bSchemaAdapter.php';
require_once __DIR__ . '/validator/Gstr1Validator.php';
require_once __DIR__ . '/validator/Gstr3bValidator.php';

class SchemaVersionManager {
    private string $currentVersion = 'v1.0';
    private array $adapters = [];
    private array $validators = [];

    public function __construct() {
        $this->registerAdapter('gstr1', new Gstr1SchemaAdapter());
        $this->registerAdapter('gstr3b', new Gstr3bSchemaAdapter());
        $this->registerValidator('gstr1', new Gstr1Validator());
        $this->registerValidator('gstr3b', new Gstr3bValidator());
    }

    public function registerAdapter(string $returnType, SchemaAdapterInterface $adapter): void {
        $this->adapters[$returnType] = $adapter;
    }

    public function registerValidator(string $returnType, ValidatorInterface $validator): void {
        $this->validators[$returnType] = $validator;
    }

    public function getAdapter(string $returnType): ?SchemaAdapterInterface {
        return $this->adapters[$returnType] ?? null;
    }

    public function getValidator(string $returnType): ?ValidatorInterface {
        return $this->validators[$returnType] ?? null;
    }

    public function getCurrentVersion(): string {
        return $this->currentVersion;
    }

    public function setCurrentVersion(string $version): void {
        $this->currentVersion = $version;
    }
}
