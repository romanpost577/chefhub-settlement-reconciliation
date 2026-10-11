<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation\Ingestion;

use PHPUnit\Framework\Attributes\After;
use RuntimeException;

trait TempFiles
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    #[After]
    protected function removeTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir(dirname($file))) {
                rmdir(dirname($file));
            }
        }
        $this->tempFiles = [];
    }

    private function tempFile(string $content, string $name = 'source.csv'): string
    {
        $directory = sys_get_temp_dir() . '/reconciliation-test-' . bin2hex(random_bytes(6));
        if (!mkdir($directory) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create temp directory');
        }
        $path = $directory . '/' . $name;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
