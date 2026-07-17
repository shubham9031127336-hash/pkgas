<?php

require_once __DIR__ . '/SchemaVersionManager.php';
require_once __DIR__ . '/mapper/Gstr1Mapper.php';
require_once __DIR__ . '/mapper/Gstr3bMapper.php';

class GstExportEngine {
    private SchemaVersionManager $schemaManager;
    private array $mappers = [];

    public function __construct() {
        $this->schemaManager = new SchemaVersionManager();
        $this->mappers['gstr1'] = new Gstr1Mapper();
        $this->mappers['gstr3b'] = new Gstr3bMapper();
    }

    public function export(PDO $pdo, int $returnId): string {
        // Load return metadata
        $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
        $stmt->execute([$returnId]);
        $return = $stmt->fetch();

        if (!$return) {
            throw new RuntimeException("Return #$returnId not found");
        }

        $returnType = $return['return_type'];

        // Validate
        $validator = $this->schemaManager->getValidator($returnType);
        if ($validator) {
            $validationResult = $validator->validate($pdo, $returnId);
            if ($validationResult->hasCriticalErrors()) {
                $errMsgs = [];
                foreach ($validationResult->getErrors() as $err) {
                    $errMsgs[] = $err['message'];
                }
                throw new RuntimeException('GST Validation failed: ' . implode('; ', $errMsgs));
            }
        }

        // Map ERP data → DTO
        $mapper = $this->mappers[$returnType] ?? null;
        if (!$mapper) {
            throw new RuntimeException("No mapper registered for return type: $returnType");
        }

        // Parse period from the return's gst_period
        $period = $this->parsePeriod($return['gst_period'], $return['financial_year']);
        $dto = $mapper->map($pdo, $return['business_key'], $period);

        // Adapt DTO → official JSON array
        $adapter = $this->schemaManager->getAdapter($returnType);
        if (!$adapter) {
            throw new RuntimeException("No schema adapter for return type: $returnType");
        }

        $jsonArray = $adapter->adapt($dto, $this->schemaManager->getCurrentVersion());

        // Encode
        return json_encode($jsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function parsePeriod(string $gstPeriod, string $financialYear): array {
        $parts = explode('-', $gstPeriod);
        if (count($parts) !== 2) {
            throw new RuntimeException("Invalid GST period format: $gstPeriod");
        }
        return [
            'month' => intval($parts[0]),
            'year' => intval($parts[1]),
            'gst_period' => $gstPeriod,
            'financial_year' => $financialYear,
        ];
    }
}
