<?php

class ValidationResult {
    private array $errors = [];
    private array $warnings = [];
    private array $infos = [];

    public function addError(string $type, string $message, string $refType = '', int $refId = 0, string $field = '', string $value = ''): void {
        $this->errors[] = ['type' => $type, 'message' => $message, 'ref_type' => $refType, 'ref_id' => $refId, 'field' => $field, 'value' => $value];
    }

    public function addWarning(string $type, string $message, string $refType = '', int $refId = 0, string $field = '', string $value = ''): void {
        $this->warnings[] = ['type' => $type, 'message' => $message, 'ref_type' => $refType, 'ref_id' => $refId, 'field' => $field, 'value' => $value];
    }

    public function addInfo(string $type, string $message, string $refType = '', int $refId = 0): void {
        $this->infos[] = ['type' => $type, 'message' => $message, 'ref_type' => $refType, 'ref_id' => $refId];
    }

    public function hasCriticalErrors(): bool {
        return !empty($this->errors);
    }

    public function getErrors(): array { return $this->errors; }
    public function getWarnings(): array { return $this->warnings; }
    public function getInfos(): array { return $this->infos; }
    public function getErrorCount(): int { return count($this->errors); }
    public function getWarningCount(): int { return count($this->warnings); }

    public function merge(ValidationResult $other): void {
        $this->errors = array_merge($this->errors, $other->errors);
        $this->warnings = array_merge($this->warnings, $other->warnings);
        $this->infos = array_merge($this->infos, $other->infos);
    }
}
