<?php

interface ValidatorInterface {
    public function validate(PDO $pdo, int $returnId): ValidationResult;
}
