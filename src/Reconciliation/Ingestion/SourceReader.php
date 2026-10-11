<?php

declare(strict_types=1);

namespace App\Reconciliation\Ingestion;

interface SourceReader
{
    /**
     * @throws SourceFileException when the file is missing, unreadable or structurally invalid
     */
    public function read(string $path): IngestionResult;
}
