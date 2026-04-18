<?php

declare(strict_types=1);

namespace YtDlpGui\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function basename;
use function bin2hex;
use function clearstatcache;
use function copy;
use function dirname;
use function escapeshellarg;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fread;
use function gmdate;
use function hash_file;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_readable;
use function json_encode;
use function mkdir;
use function passthru;
use function pathinfo;
use function random_bytes;
use function rename;
use function rmdir;
use function rtrim;
use function shell_exec;
use function sprintf;
use function stream_context_create;
use function stream_copy_to_stream;
use function strlen;
use function str_starts_with;
use function strpos;
use function substr;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use function unlink;

#[AsCommand(
    name: 'app:prepare:windows-runtime',
    description: 'Downloads and extracts Windows VC++ runtime DLLs required by the Boson build.',
)]
final class PrepareWindowsRuntimeCommand extends Command
{
    /**
     * @var list<non-empty-string>
     */
    private const array REQUIRED_DLLS = [
        'vcruntime140.dll',
        'vcruntime140_1.dll',
        'msvcp140.dll',
        'msvcp140_atomic_wait.dll',
    ];

    private const string DEFAULT_SOURCE_URL = 'https://aka.ms/vs/17/release/vc_redist.x64.exe';

    protected function configure(): void
    {
        $this
            ->addOption(
                name: 'arch',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Target Windows architecture.',
                default: 'amd64',
            )
            ->addOption(
                name: 'output',
                shortcut: 'o',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Directory where runtime DLLs will be stored.',
                default: 'var/windows-runtime/amd64',
            )
            ->addOption(
                name: 'manual-source',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Optional directory with pre-downloaded DLLs.',
                default: 'resources/windows-runtime/amd64',
            )
            ->addOption(
                name: 'cache-dir',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Directory for downloaded redistributable cache.',
                default: 'var/windows-runtime/cache/amd64',
            )
            ->addOption(
                name: 'source-url',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Official Microsoft redistributable URL.',
                default: self::DEFAULT_SOURCE_URL,
            )
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Re-download and re-extract runtime DLLs even if cache already exists.',
            )
            ->addOption(
                name: 'no-download',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Do not download redistributable; only use existing manual-source/output files.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = dirname(__DIR__, 2);

        $archOption = $input->getOption('arch');
        $arch =
            is_string($archOption)
            ? trim($archOption)
            : '';

        if ($arch !== 'amd64') {
            $io->error('Only Windows amd64 runtime auto-fetch is supported right now.');

            return Command::INVALID;
        }

        $outputDir = $this->resolvePath(
            projectDir: $projectDir,
            path: $this->requireStringOption(
                option: $input->getOption('output'),
                optionName: '--output',
            ),
        );
        $manualSourceDir = $this->resolvePath(
            projectDir: $projectDir,
            path: $this->requireStringOption(
                option: $input->getOption('manual-source'),
                optionName: '--manual-source',
            ),
        );
        $cacheDir = $this->resolvePath(
            projectDir: $projectDir,
            path: $this->requireStringOption(
                option: $input->getOption('cache-dir'),
                optionName: '--cache-dir',
            ),
        );
        $sourceUrl = $this->requireStringOption(
            option: $input->getOption('source-url'),
            optionName: '--source-url',
        );
        $force = (bool) $input->getOption('force');
        $noDownload = (bool) $input->getOption('no-download');

        if (!$force && $this->hasRuntimeFiles(directory: $outputDir)) {
            $io->success(sprintf('Windows runtime is ready in %s', $outputDir));

            return Command::SUCCESS;
        }

        $this->ensureDirectory(path: $outputDir);

        if ($this->hasRuntimeFiles(directory: $manualSourceDir)) {
            $this->copyRuntimeFiles(
                sourceDir: $manualSourceDir,
                targetDir: $outputDir,
            );
            $this->writeManifest(
                outputDir: $outputDir,
                payload: [
                    'preparedAt' => gmdate(DATE_ATOM),
                    'mode' => 'manual-copy',
                    'manualSource' => $manualSourceDir,
                ],
            );

            $io->success(sprintf('Windows runtime copied from %s to %s', $manualSourceDir, $outputDir));

            return Command::SUCCESS;
        }

        if ($noDownload) {
            $io->error(sprintf('Windows runtime DLLs were not found in %s and downloading is disabled.', $manualSourceDir));

            return Command::FAILURE;
        }

        $this->ensureDirectory(path: $cacheDir);

        $archivePath = $cacheDir . '/vc_redist.x64.exe';
        if ($force || !is_file($archivePath) || !is_readable($archivePath)) {
            $io->section('Downloading Microsoft VC++ redistributable');
            $this->downloadFile(
                sourceUrl: $sourceUrl,
                targetPath: $archivePath,
            );
        } else {
            $io->note(sprintf('Using cached redistributable: %s', $archivePath));
        }

        $extractor = $this->detectExtractor();
        $io->section(sprintf('Extracting runtime with %s', $extractor['name']));

        $temporaryRoot = $this->createTemporaryDirectory(prefix: 'ytdlpgui-windows-runtime-');

        try {
            $embeddedCabinets = $this->carveEmbeddedCabinets(
                archivePath: $archivePath,
                targetDir: $temporaryRoot . '/embedded-cabs',
            );

            foreach ($embeddedCabinets as $index => $embeddedCabinet) {
                $this->extractArchive(
                    extractor: $extractor,
                    archivePath: $embeddedCabinet,
                    targetDir: sprintf('%s/payload-%d', $temporaryRoot, $index),
                );
            }

            $this->extractNestedCabinets(baseDir: $temporaryRoot, extractor: $extractor);

            $discoveredPaths = $this->findRequiredDllPaths(baseDir: $temporaryRoot);
            $missing = $this->missingDllNames(discoveredPaths: $discoveredPaths);

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'Could not locate required DLLs after extraction: %s',
                    implode(', ', $missing),
                ));
            }

            $this->copyDiscoveredRuntimeFiles(
                discoveredPaths: $discoveredPaths,
                targetDir: $outputDir,
            );
            $archiveSha256 = hash_file(
                algo: 'sha256',
                filename: $archivePath,
            );
            $this->writeManifest(
                outputDir: $outputDir,
                payload: [
                    'preparedAt' => gmdate(DATE_ATOM),
                    'mode' => 'downloaded',
                    'sourceUrl' => $sourceUrl,
                    'archiveSha256' => is_string($archiveSha256) ? $archiveSha256 : '',
                    'extractor' => $extractor['name'],
                ],
            );
        } finally {
            $this->removeDirectory(path: $temporaryRoot);
        }

        $io->success(sprintf('Windows runtime is ready in %s', $outputDir));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function carveEmbeddedCabinets(string $archivePath, string $targetDir): array
    {
        $this->ensureDirectory(path: $targetDir);

        $archiveContents = file_get_contents($archivePath);
        if (!is_string($archiveContents) || $archiveContents === '') {
            throw new RuntimeException(sprintf('Cannot read archive contents: %s', $archivePath));
        }

        $offsets = $this->scanEmbeddedCabOffsets(archiveContents: $archiveContents);
        if ($offsets === []) {
            throw new RuntimeException('Could not find embedded CAB payloads in the Microsoft redistributable.');
        }

        $carvedCabinets = [];

        foreach ($offsets as $index => $offset) {
            $cabinetPath = sprintf('%s/embedded-%d.cab', $targetDir, $index);
            $cabinetContents = substr($archiveContents, $offset);
            if ($cabinetContents === '') {
                throw new RuntimeException(sprintf('Failed to carve embedded CAB at offset %d', $offset));
            }

            file_put_contents($cabinetPath, $cabinetContents);
            $carvedCabinets[] = $cabinetPath;
        }

        return $carvedCabinets;
    }

    /**
     * @return list<int>
     */
    private function scanEmbeddedCabOffsets(string $archiveContents): array
    {
        $offsets = [];
        $cursor = 0;

        while (true) {
            $offset = strpos($archiveContents, 'MSCF', $cursor);
            if ($offset === false) {
                break;
            }

            $offsets[] = $offset;
            $cursor = $offset + 4;
        }

        return $offsets;
    }

    /**
     * @param array<string, string> $discoveredPaths
     *
     * @return list<non-empty-string>
     */
    private function missingDllNames(array $discoveredPaths): array
    {
        $missing = [];

        foreach (self::REQUIRED_DLLS as $dllName) {
            if (!array_key_exists($dllName, $discoveredPaths)) {
                $missing[] = $dllName;
            }
        }

        return $missing;
    }

    private function requireStringOption(mixed $option, string $optionName): string
    {
        if (!is_string($option) || trim($option) === '') {
            throw new RuntimeException(sprintf('Option %s must be a non-empty string.', $optionName));
        }

        return trim($option);
    }

    private function resolvePath(string $projectDir, string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('Path cannot be empty.');
        }

        if ($path[0] === '/' || (strlen($path) > 2 && $path[1] === ':')) {
            return rtrim($path, '/\\');
        }

        return rtrim($projectDir . '/' . $path, '/\\');
    }

    private function hasRuntimeFiles(string $directory): bool
    {
        foreach (self::REQUIRED_DLLS as $dllName) {
            $path = $directory . '/' . $dllName;
            if (!is_file($path) || !is_readable($path)) {
                return false;
            }
        }

        return true;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $path));
        }
    }

    private function downloadFile(string $sourceUrl, string $targetPath): void
    {
        $this->ensureDirectory(path: dirname($targetPath));

        $temporaryPath = $targetPath . '.tmp';
        @unlink($temporaryPath);

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'timeout' => 300,
                'user_agent' => 'YtDlpGui Windows Runtime Fetcher',
            ],
        ]);

        $source = fopen($sourceUrl, 'rb', false, $context);
        if ($source === false) {
            throw new RuntimeException(sprintf('Cannot open download URL: %s', $sourceUrl));
        }

        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException(sprintf('Cannot open target file for writing: %s', $temporaryPath));
        }

        try {
            $bytesCopied = stream_copy_to_stream($source, $target);
            if ($bytesCopied === false || $bytesCopied <= 0) {
                throw new RuntimeException(sprintf('Failed to download runtime from %s', $sourceUrl));
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        if (!rename($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf('Cannot move downloaded runtime to %s', $targetPath));
        }
    }

    /**
     * @return array{name: non-empty-string, command: non-empty-string}
     */
    private function detectExtractor(): array
    {
        $candidates = [
            ['name' => '7zz', 'command' => '7zz'],
            ['name' => '7z', 'command' => '7z'],
            ['name' => 'cabextract', 'command' => 'cabextract'],
            ['name' => 'bsdtar', 'command' => 'bsdtar'],
        ];

        foreach ($candidates as $candidate) {
            $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($candidate['command']) . ' 2>/dev/null'));
            if ($resolved !== '') {
                return [
                    'name' => $candidate['name'],
                    'command' => $resolved,
                ];
            }
        }

        throw new RuntimeException('No supported extractor was found. Install one of: 7z, cabextract, bsdtar.');
    }

    /**
     * @param array{name: non-empty-string, command: non-empty-string} $extractor
     */
    private function extractArchive(array $extractor, string $archivePath, string $targetDir): void
    {
        $this->ensureDirectory(path: $targetDir);

        $command = match ($extractor['name']) {
            '7zz', '7z' => sprintf(
                '%s x -y %s -o%s >/dev/null',
                escapeshellarg($extractor['command']),
                escapeshellarg($archivePath),
                escapeshellarg($targetDir),
            ),
            'cabextract' => sprintf(
                '%s -d %s %s >/dev/null',
                escapeshellarg($extractor['command']),
                escapeshellarg($targetDir),
                escapeshellarg($archivePath),
            ),
            'bsdtar' => sprintf(
                '%s -xf %s -C %s >/dev/null 2>&1',
                escapeshellarg($extractor['command']),
                escapeshellarg($archivePath),
                escapeshellarg($targetDir),
            ),
            default => throw new RuntimeException(sprintf('Unsupported extractor: %s', $extractor['name'])),
        };

        passthru($command, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf('Failed to extract archive %s', basename($archivePath)));
        }
    }

    /**
     * @param array{name: non-empty-string, command: non-empty-string} $extractor
     */
    private function extractNestedCabinets(string $baseDir, array $extractor): void
    {
        $cabinetPaths = [];
        foreach ($this->iterateFiles(baseDir: $baseDir) as $file) {
            if (!$this->isCabinetFile(path: $file->getPathname())) {
                continue;
            }

            $cabinetPaths[] = $file->getPathname();
        }

        foreach ($cabinetPaths as $cabinetPath) {
            $cabinetOutputDir = dirname($cabinetPath) . '/' . pathinfo($cabinetPath, PATHINFO_FILENAME) . '-extracted';
            $this->ensureDirectory(path: $cabinetOutputDir);

            $command = match ($extractor['name']) {
                '7zz', '7z' => sprintf(
                    '%s x -y %s -o%s >/dev/null',
                    escapeshellarg($extractor['command']),
                    escapeshellarg($cabinetPath),
                    escapeshellarg($cabinetOutputDir),
                ),
                'cabextract' => sprintf(
                    '%s -d %s %s >/dev/null',
                    escapeshellarg($extractor['command']),
                    escapeshellarg($cabinetOutputDir),
                    escapeshellarg($cabinetPath),
                ),
                'bsdtar' => sprintf(
                    '%s -xf %s -C %s >/dev/null 2>&1',
                    escapeshellarg($extractor['command']),
                    escapeshellarg($cabinetPath),
                    escapeshellarg($cabinetOutputDir),
                ),
                default => throw new RuntimeException(sprintf('Unsupported extractor: %s', $extractor['name'])),
            };

            passthru($command, $exitCode);
            if ($exitCode !== 0) {
                continue;
            }
        }
    }

    private function isCabinetFile(string $path): bool
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }

        try {
            $signature = fread($stream, 4);
        } finally {
            fclose($stream);
        }

        return $signature === 'MSCF';
    }

    /**
     * @return array<string, string>
     */
    private function findRequiredDllPaths(string $baseDir): array
    {
        $discovered = [];

        foreach ($this->iterateFiles(baseDir: $baseDir) as $file) {
            $filename = strtolower($file->getFilename());

            if (!is_readable($file->getPathname())) {
                continue;
            }

            foreach (self::REQUIRED_DLLS as $dllName) {
                $normalizedDllName = strtolower($dllName);
                if ($filename !== $normalizedDllName && !str_starts_with($filename, $normalizedDllName . '_')) {
                    continue;
                }

                $discovered[$normalizedDllName] = $file->getPathname();
                break;
            }
        }

        return $discovered;
    }

    /**
     * @param array<string, string> $discoveredPaths
     */
    private function copyDiscoveredRuntimeFiles(array $discoveredPaths, string $targetDir): void
    {
        $this->ensureDirectory(path: $targetDir);

        foreach (self::REQUIRED_DLLS as $dllName) {
            $sourcePath = $discoveredPaths[$dllName] ?? null;
            if (!is_string($sourcePath) || $sourcePath === '') {
                throw new RuntimeException(sprintf('Missing discovered path for %s', $dllName));
            }

            $targetPath = $targetDir . '/' . $dllName;
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException(sprintf('Failed to copy %s to %s', $dllName, $targetPath));
            }
        }

        clearstatcache();
    }

    private function copyRuntimeFiles(string $sourceDir, string $targetDir): void
    {
        $this->ensureDirectory(path: $targetDir);

        foreach (self::REQUIRED_DLLS as $dllName) {
            $sourcePath = $sourceDir . '/' . $dllName;
            $targetPath = $targetDir . '/' . $dllName;
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException(sprintf('Failed to copy %s to %s', $dllName, $targetPath));
            }
        }
    }

    /**
     * @return iterable<array-key, SplFileInfo>
     */
    private function iterateFiles(string $baseDir): iterable
    {
        if (!is_dir($baseDir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $baseDir,
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var list<SplFileInfo> $files */
        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    private function createTemporaryDirectory(string $prefix): string
    {
        $baseDir = rtrim(sys_get_temp_dir(), '/\\');
        $path = $baseDir . '/' . $prefix . bin2hex(random_bytes(8));
        $this->ensureDirectory(path: $path);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /**
     * @param array<string, string> $payload
     */
    private function writeManifest(string $outputDir, array $payload): void
    {
        file_put_contents(
            $outputDir . '/manifest.json',
            (string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}



