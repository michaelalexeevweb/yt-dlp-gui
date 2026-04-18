<?php

declare(strict_types=1);

namespace YtDlpGui\Download;

use function implode;

final readonly class DownloadResult
{
    /**
     * @param list<string> $outputLines
     */
    public function __construct(
        public bool $isSuccess,
        public string $message,
        public string $downloadsDirectory,
        public array $outputLines = [],
        public int $exitCode = 0,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     downloadsDirectory: string,
     *     output: string,
     *     exitCode: int
     * }
     */
    public function toPayload(): array
    {
        return [
            'success' => $this->isSuccess,
            'message' => $this->message,
            'downloadsDirectory' => $this->downloadsDirectory,
            'output' => implode(PHP_EOL, $this->outputLines),
            'exitCode' => $this->exitCode,
        ];
    }
}
