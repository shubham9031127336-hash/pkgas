<?php

interface MapperInterface {
    public function map(PDO $pdo, string $businessKey, array $period): object;
}
