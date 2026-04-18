<?php

declare(strict_types=1);

namespace YtDlpGui\Download;

use Phar;
use RuntimeException;

use function basename;
use function chmod;
use function class_exists;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filter_var;
use function getenv;
use function hash_file;
use function implode;
use function is_dir;
use function is_executable;
use function is_file;
use function is_string;
use function mkdir;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function sys_get_temp_dir;
use function trim;

use const FILTER_VALIDATE_URL;

final readonly class YtDlpDownloader
{
    public function __construct(
        private string $downloadsDirectory = '/downloads',
        private string $outputTemplate = '%(title)s.%(ext)s',
    ) {
    }

    public function download(string $url): DownloadResult
    {
        $normalizedUrl = trim($url);

        if ($normalizedUrl === '') {
            return new DownloadResult(
                isSuccess: false,
                message: 'URL is empty.',
                downloadsDirectory: $this->downloadsDirectory,
            );
        }

        if (filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
            return new DownloadResult(
                isSuccess: false,
                message: 'URL is invalid. Please provide a full URL including protocol.',
                downloadsDirectory: $this->downloadsDirectory,
            );
        }

        try {
            $this->ensureDownloadsDirectoryExists();
        } catch (RuntimeException $e) {
            return new DownloadResult(
                isSuccess: false,
                message: $e->getMessage(),
                downloadsDirectory: $this->downloadsDirectory,
            );
        }

        $outputPattern = sprintf('%s/%s', $this->downloadsDirectory, $this->outputTemplate);

        $bundledOnly = getenv('YTDLP_BUNDLED_ONLY') === '1';
        $toolsDirectory = $this->resolveBundledToolsDirectory();
        $ytDlpBinary = $this->resolveYtDlpBinary(
            bundledOnly: $bundledOnly,
            bundledToolsDirectory: $toolsDirectory,
        );

        $override = getenv('YTDLP_BIN');
        if (is_string($override) && $override !== '' && !$this->isUsableBinary($override)) {
            return new DownloadResult(
                isSuccess: false,
                message: sprintf('YTDLP_BIN points to an unusable file: %s', $override),
                downloadsDirectory: $this->downloadsDirectory,
            );
        }

        if ($ytDlpBinary === null) {
            if ($bundledOnly) {
                $notFoundMessage = 'Bundled yt-dlp was not found. Place yt-dlp in <app>/tools or set YTDLP_BIN.';
            } elseif (PHP_OS_FAMILY === 'Windows') {
                $notFoundMessage = 'yt-dlp.exe was not found. Install yt-dlp/ffmpeg/ffprobe and add them to PATH, or provide YTDLP_BIN / YTDLP_TOOLS_DIR.';
            } else {
                $notFoundMessage = 'yt-dlp binary was not found. Set YTDLP_BIN or place a yt-dlp binary next to the compiled app.';
            }

            return new DownloadResult(
                isSuccess: false,
                message: $notFoundMessage,
                downloadsDirectory: $this->downloadsDirectory,
            );
        }

        $ffmpegLocationArgument = '';
        if (is_string($toolsDirectory) && $toolsDirectory !== '') {
            $ffmpegLocationArgument = sprintf(' --ffmpeg-location %s', escapeshellarg($toolsDirectory));
        }

        $command = sprintf(
            'yt-dlp --newline --restrict-filenames --concurrent-fragments 100%s --output %s -- %s 2>&1',
            $ffmpegLocationArgument,
            $this->quoteOutputTemplateArgument($outputPattern),
            escapeshellarg($normalizedUrl),
        );

        $command = str_replace('yt-dlp', escapeshellarg($ytDlpBinary), $command);

        $outputLines = [];
        $exitCode = 0;

        exec($command, $outputLines, $exitCode);

        if ($exitCode !== 0) {
            $joinedOutput = trim(implode("\n", $outputLines));

            if ($exitCode === 126 && str_contains($joinedOutput, 'bad interpreter')) {
                return new DownloadResult(
                    isSuccess: false,
                    message: sprintf(
                        'yt-dlp launcher is broken (%s). Reinstall yt-dlp, or set YTDLP_BIN to a working standalone binary.',
                        $ytDlpBinary,
                    ),
                    downloadsDirectory: $this->downloadsDirectory,
                    outputLines: $outputLines,
                    exitCode: $exitCode,
                );
            }

            return new DownloadResult(
                isSuccess: false,
                message: sprintf('yt-dlp failed with exit code %d.', $exitCode),
                downloadsDirectory: $this->downloadsDirectory,
                outputLines: $outputLines,
                exitCode: $exitCode,
            );
        }

        return new DownloadResult(
            isSuccess: true,
            message: sprintf(
                'Download completed. Files are saved to %s',
                $this->downloadsDirectory,
            ),
            downloadsDirectory: $this->downloadsDirectory,
            outputLines: $outputLines,
            exitCode: $exitCode,
        );
    }

    private function ensureDownloadsDirectoryExists(): void
    {
        if (is_dir($this->downloadsDirectory)) {
            return;
        }

        if (!mkdir($this->downloadsDirectory, 0775, true) && !is_dir($this->downloadsDirectory)) {
            throw new RuntimeException(sprintf('Cannot create downloads directory: %s', $this->downloadsDirectory));
        }
    }

    private function resolveYtDlpBinary(bool $bundledOnly, ?string $bundledToolsDirectory): ?string
    {
        $override = getenv('YTDLP_BIN');

        if (
            is_string($override)
            && $override !== ''
            && $this->isUsableBinary($override)
            && $this->isOperationalYtDlp($override)
        ) {
            return $override;
        }

        if (is_string($bundledToolsDirectory) && $bundledToolsDirectory !== '') {
            $isWindows = PHP_OS_FAMILY === 'Windows';
            $bundledYtDlp = $bundledToolsDirectory . ($isWindows ? '/yt-dlp.exe' : '/yt-dlp');
            if ($this->isUsableBinary($bundledYtDlp) && $this->isOperationalYtDlp($bundledYtDlp)) {
                return $bundledYtDlp;
            }
        }

        if ($bundledOnly) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // On Windows: look for yt-dlp.exe next to the running exe, then in PATH.
            $candidates = [];

            if (class_exists(Phar::class, false)) {
                $running = Phar::running(false);
                if ($running !== '') {
                    $candidates[] = str_replace('/', '\\', dirname($running)) . '\\yt-dlp.exe';
                }
            }

            $candidates[] = 'yt-dlp.exe';
            $candidates[] = 'yt-dlp';

            foreach ($candidates as $candidate) {
                if ($this->isUsableBinary($candidate) && $this->isOperationalYtDlp($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        $candidates = [
            '/opt/homebrew/bin/yt-dlp',
            '/usr/local/bin/yt-dlp',
            'yt-dlp',
        ];

        foreach ($candidates as $candidate) {
            if ($this->isUsableBinary($candidate) && $this->isOperationalYtDlp($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveBundledToolsDirectory(): ?string
    {
        $override = getenv('YTDLP_TOOLS_DIR');
        if (is_string($override) && $override !== '' && $this->isValidToolsDirectory($override)) {
            return $override;
        }

        foreach (
            [
                dirname(__DIR__, 2) . '/dist/build/tools',
                dirname(__DIR__, 2) . '/var/tools',
                dirname(__DIR__, 2) . '/tools'
            ] as $projectTools
        ) {
            if ($this->isValidToolsDirectory($projectTools)) {
                return $projectTools;
            }
        }

        $argv0 = $_SERVER['argv'][0] ?? null;
        if (is_string($argv0) && $argv0 !== '') {
            $candidate = dirname($argv0) . '/tools';

            if ($this->isValidToolsDirectory($candidate)) {
                return $candidate;
            }
        }

        return $this->extractEmbeddedToolsFromPhar();
    }

    private function extractEmbeddedToolsFromPhar(): ?string
    {
        if (!class_exists(Phar::class)) {
            return null;
        }

        $running = Phar::running(false);
        if ($running === '') {
            return null;
        }

        $pharTools = $this->resolveEmbeddedPharTools($running);
        if ($pharTools === null) {
            return null;
        }

        $signature = hash_file('sha256', $running);
        if ($signature === false) {
            $signature = preg_replace('/[^A-Za-z0-9_.-]/', '_', $running);
            if (!is_string($signature)) {
                return null;
            }
        }

        $targetDirectory = sprintf('%s/ytdlpgui-tools/%s', sys_get_temp_dir(), $signature);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            return null;
        }

        // Check if tools are already extracted with matching version signature
        if ($this->areToolsExtractedAndValid($targetDirectory, $pharTools)) {
            return $targetDirectory;
        }

        foreach ($pharTools as $toolName => $pharPath) {
            $binaryContent = file_get_contents($pharPath);
            if (!is_string($binaryContent) || $binaryContent === '') {
                return null;
            }

            $targetPath = sprintf('%s/%s', $targetDirectory, $toolName);

            if (file_put_contents($targetPath, $binaryContent) === false) {
                return null;
            }

            chmod($targetPath, 0755);
        }

        return $targetDirectory;
    }

    /**
     * @param array<string, string> $pharTools
     */
    private function areToolsExtractedAndValid(string $targetDirectory, array $pharTools): bool
    {
        foreach ($pharTools as $pharPath) {
            $toolName = basename($pharPath);
            $targetPath = sprintf('%s/%s', $targetDirectory, $toolName);

            if (!is_file($targetPath) || !is_executable($targetPath)) {
                return false;
            }
        }

        return true;
    }

    private function isValidToolsDirectory(string $directory): bool
    {
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        return is_dir($directory)
            && $this->isUsableBinary($directory . '/yt-dlp' . $ext)
            && $this->isUsableBinary($directory . '/ffmpeg' . $ext)
            && $this->isUsableBinary($directory . '/ffprobe' . $ext);
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveEmbeddedPharTools(string $running): ?array
    {
        foreach (['dist/build/tools', 'var/tools', 'tools'] as $prefix) {
            $pharTools = [
                'yt-dlp' => sprintf('phar://%s/%s/yt-dlp', $running, $prefix),
                'ffmpeg' => sprintf('phar://%s/%s/ffmpeg', $running, $prefix),
                'ffprobe' => sprintf('phar://%s/%s/ffprobe', $running, $prefix),
            ];

            $allExist = true;
            foreach ($pharTools as $pharPath) {
                if (!file_exists($pharPath)) {
                    $allExist = false;
                    break;
                }
            }

            if ($allExist) {
                return $pharTools;
            }
        }

        return null;
    }

    private function isUsableBinary(string $binaryPath): bool
    {
        $isAbsolute = str_contains($binaryPath, '/') || str_contains($binaryPath, '\\');

        if ($isAbsolute) {
            return is_file($binaryPath) && is_executable($binaryPath);
        }

        // Bare binary name — check via shell PATH lookup.
        if (PHP_OS_FAMILY === 'Windows') {
            $whereOutput = [];
            $whereExitCode = 0;
            exec(
                command: sprintf('where %s 2>NUL', escapeshellarg($binaryPath)),
                output: $whereOutput,
                result_code: $whereExitCode,
            );

            if ($whereExitCode !== 0 || $whereOutput === []) {
                return false;
            }

            $resolved = trim($whereOutput[0]);
            return $resolved !== '' && is_file($resolved);
        }

        $whichOutput = [];
        $whichExitCode = 0;

        exec(
            command: sprintf('command -v %s 2>/dev/null', escapeshellarg($binaryPath)),
            output: $whichOutput,
            result_code: $whichExitCode,
        );

        if ($whichExitCode !== 0 || $whichOutput === []) {
            return false;
        }

        $resolved = trim($whichOutput[0]);

        return $resolved !== '' && basename($resolved) !== '';
    }

    private function isOperationalYtDlp(string $binaryPath): bool
    {
        $output = [];
        $exitCode = 0;

        exec(
            command: sprintf('%s %s 2>&1', escapeshellarg($binaryPath), escapeshellarg('--version')),
            output: $output,
            result_code: $exitCode,
        );

        return $exitCode === 0;
    }

    private function quoteOutputTemplateArgument(string $template): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return escapeshellarg($template);
        }

        // In cmd/batch files '%' triggers env expansion, so keep yt-dlp placeholders via '%%'.
        $escaped = str_replace('%', '%%', $template);

        return '"' . str_replace('"', '""', $escaped) . '"';
    }
}
