<?php

declare(strict_types=1);

namespace YtDlpGui\Download;

use Phar;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function chmod;
use function class_exists;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filter_var;
use function get_current_user;
use function glob;
use function hash_file;
use function in_array;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_numeric;
use function is_readable;
use function is_string;
use function json_decode;
use function krsort;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;

final readonly class YtDlpJobService
{
    private const string DEFAULT_FORMAT = 'bestvideo*+bestaudio/best';
    private const string PRESET_MP3 = 'preset:mp3';
    private const string PRESET_MP4 = 'preset:mp4';
    private const MP3_BITRATES = [64, 128, 256, 320];
    private const MP4_HEIGHTS = [144, 240, 360, 480, 720, 1080, 1440, 2160];

    private string $jobsDirectory;

    public function __construct(
        private string $downloadsDirectory,
        ?string $jobsDirectory = null,
        private string $outputTemplate = '%(title)s.%(ext)s',
    ) {
        $this->jobsDirectory = $jobsDirectory !== null && $jobsDirectory !== ''
            ? $jobsDirectory
            : self::defaultJobsDirectory();
    }

    private static function defaultJobsDirectory(): string
    {
        $tmpRoot = rtrim(sys_get_temp_dir(), '/');
        $user = trim(get_current_user());

        if ($user === '') {
            $user = 'unknown-user';
        }

        return sprintf('%s/ytdlpgui/%s/download-jobs', $tmpRoot, $user);
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     opened: bool
     * }
     */
    public function openDownloadsFolder(): array
    {
        $directory = $this->downloadsDirectory;

        try {
            $this->ensureDirectoryExists($directory);
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'opened' => false,
            ];
        }

        $command = sprintf('open %s >/dev/null 2>&1', escapeshellarg($directory));

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'Cannot open downloads folder.',
                'opened' => false,
            ];
        }

        return [
            'success' => true,
            'message' => 'Downloads folder opened.',
            'opened' => true,
        ];
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     profiles: list<array{id: string, label: string, qualities: list<array{id: string, label: string, formatId: string}>}>
     * }
     */
    public function listFormats(string $url): array
    {
        $normalizedUrl = trim($url);

        if ($normalizedUrl === '') {
            return [
                'success' => false,
                'message' => 'URL is empty.',
                'profiles' => [],
            ];
        }

        if (filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
            return [
                'success' => false,
                'message' => 'URL is invalid. Please provide a full URL including protocol.',
                'profiles' => [],
            ];
        }

        $toolsDirectory = $this->resolveToolsDirectory();
        $ytDlpBinary = $this->resolveYtDlpBinary($toolsDirectory);

        if ($ytDlpBinary === null) {
            return [
                'success' => false,
                'message' => $this->missingYtDlpMessage(),
                'profiles' => [],
            ];
        }

        $ffmpegLocationArgument = '';
        if (is_string($toolsDirectory) && $toolsDirectory !== '') {
            $ffmpegLocationArgument = sprintf(' --ffmpeg-location %s', escapeshellarg($toolsDirectory));
        }

        $command = sprintf(
            '%s --dump-single-json --skip-download --no-warnings%s -- %s 2>&1',
            escapeshellarg($ytDlpBinary),
            $ffmpegLocationArgument,
            escapeshellarg($normalizedUrl),
        );

        $outputLines = [];
        $exitCode = 0;
        exec($command, $outputLines, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'Cannot fetch format list for this URL.',
                'profiles' => [],
            ];
        }

        $json = trim(implode("\n", $outputLines));

        if ($json === '') {
            return [
                'success' => false,
                'message' => 'Format list is empty.',
                'profiles' => [],
            ];
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [
                'success' => false,
                'message' => 'Cannot parse format list response.',
                'profiles' => [],
            ];
        }

        if (!is_array($payload) || !array_key_exists('formats', $payload) || !is_array($payload['formats'])) {
            return [
                'success' => false,
                'message' => 'No formats were reported by yt-dlp.',
                'profiles' => [],
            ];
        }

        /** @var list<array<array-key, mixed>> $formats */
        $formats = $payload['formats'];

        return [
            'success' => true,
            'message' => 'Formats loaded.',
            'profiles' => $this->buildDownloadProfiles($formats),
        ];
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     jobId: string|null,
     *     offset: int
     * }
     */
    public function start(string $url, string $formatId = self::DEFAULT_FORMAT): array
    {
        $normalizedUrl = trim($url);

        if ($normalizedUrl === '') {
            return [
                'success' => false,
                'message' => 'URL is empty.',
                'jobId' => null,
                'offset' => 0,
            ];
        }

        if (filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
            return [
                'success' => false,
                'message' => 'URL is invalid. Please provide a full URL including protocol.',
                'jobId' => null,
                'offset' => 0,
            ];
        }

        try {
            $this->ensureDirectoryExists($this->downloadsDirectory);
            $this->ensureDirectoryExists($this->jobsDirectory);
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'jobId' => null,
                'offset' => 0,
            ];
        }

        $toolsDirectory = $this->resolveToolsDirectory();
        $ytDlpBinary = $this->resolveYtDlpBinary($toolsDirectory);

        if ($ytDlpBinary === null) {
            return [
                'success' => false,
                'message' => $this->missingYtDlpMessage(),
                'jobId' => null,
                'offset' => 0,
            ];
        }

        $jobId = str_replace('.', '_', uniqid('job_', true));
        $logPath = $this->jobsDirectory . '/' . $jobId . '.log';
        $exitPath = $this->jobsDirectory . '/' . $jobId . '.exit';
        $pidPath = $this->jobsDirectory . '/' . $jobId . '.pid';
        $spawnLogPath = $this->jobsDirectory . '/' . $jobId . '.spawn.log';

        if (file_put_contents($logPath, '') === false) {
            return [
                'success' => false,
                'message' => 'Cannot create job log file.',
                'jobId' => null,
                'offset' => 0,
            ];
        }

        $ffmpegLocationArgument = '';
        if (is_string($toolsDirectory) && $toolsDirectory !== '') {
            $ffmpegLocationArgument = sprintf(' --ffmpeg-location %s', escapeshellarg($toolsDirectory));
        }

        $formatArgument = '';
        $postProcessingArgument = '';
        $postProcessorArgsArgument = '';
        $normalizedFormat = $this->normalizeFormatId($formatId);
        if ($normalizedFormat === self::PRESET_MP3 || str_starts_with($normalizedFormat, self::PRESET_MP3 . ':')) {
            $audioQuality = '0';

            if (str_starts_with($normalizedFormat, self::PRESET_MP3 . ':')) {
                $parts = explode(':', $normalizedFormat);
                $candidateBitrate = isset($parts[2]) ? (int)$parts[2] : 0;

                if (in_array($candidateBitrate, self::MP3_BITRATES, true)) {
                    $audioQuality = (string)$candidateBitrate . 'K';
                }
            }

            $formatArgument = sprintf(' --format %s', escapeshellarg('bestaudio/best'));
            $postProcessingArgument = sprintf(
                ' --extract-audio --audio-format mp3 --audio-quality %s',
                escapeshellarg($audioQuality),
            );
            $postProcessorArgsArgument = sprintf(
                ' --postprocessor-args %s',
                escapeshellarg('ffmpeg:-threads 0'),
            );
        } elseif ($normalizedFormat === self::PRESET_MP4 || str_starts_with(
                $normalizedFormat,
                self::PRESET_MP4 . ':',
            )) {
            $plan = $this->resolveMp4DownloadPlan(normalizedFormat: $normalizedFormat);

            $formatArgument = sprintf(' --format %s', escapeshellarg($plan['formatSelector']));
            if ($plan['recodeVideo']) {
                $postProcessingArgument = ' --recode-video mp4';
                $postProcessorArgsArgument = sprintf(
                    ' --postprocessor-args %s',
                    escapeshellarg('ffmpeg:-threads 0 -preset veryfast'),
                );
            }
        } elseif ($normalizedFormat !== self::DEFAULT_FORMAT) {
            $formatArgument = sprintf(' --format %s', escapeshellarg($normalizedFormat));
        }

        $outputTemplate = $this->outputTemplate;

        $downloadCommand = sprintf(
            '%s --newline --windows-filenames --concurrent-fragments 100%s%s%s%s --paths %s --output %s -- %s',
            escapeshellarg($ytDlpBinary),
            $ffmpegLocationArgument,
            $formatArgument,
            $postProcessingArgument,
            $postProcessorArgsArgument,
            escapeshellarg($this->downloadsDirectory),
            $this->quoteOutputTemplateArgument($outputTemplate),
            escapeshellarg($normalizedUrl),
        );

        if (PHP_OS_FAMILY === 'Windows') {
            $runnerPath = $this->jobsDirectory . '/' . $jobId . '.cmd';
            $runnerPathForCmd = str_replace('/', '\\', $runnerPath);
            $logPathForCmd = str_replace('/', '\\', $logPath);
            $exitPathForCmd = str_replace('/', '\\', $exitPath);
            $pidPathForCmd = str_replace('/', '\\', $pidPath);

            $runnerContents = "@echo off\r\n"
                . "setlocal\r\n"
                . $downloadCommand . sprintf(' 1>"%s" 2>&1', str_replace('"', '""', $logPathForCmd)) . "\r\n"
                . "set YTDLP_EXIT_CODE=%ERRORLEVEL%\r\n"
                . sprintf('(echo %%YTDLP_EXIT_CODE%%)>"%s"', str_replace('"', '""', $exitPathForCmd)) . "\r\n"
                . "endlocal\r\n";

            if (file_put_contents($runnerPath, $runnerContents) === false) {
                return [
                    'success' => false,
                    'message' => 'Cannot create job runner file.',
                    'jobId' => null,
                    'offset' => 0,
                ];
            }

            $runnerPathForPs = str_replace("'", "''", $runnerPathForCmd);
            $pidPathForPs = str_replace("'", "''", $pidPathForCmd);
            $psScript = sprintf(
                "\$p = Start-Process -FilePath 'cmd.exe' -ArgumentList '/C', '\"%s\"' -WindowStyle Hidden -PassThru; [System.IO.File]::WriteAllText('%s', [string]\$p.Id)",
                $runnerPathForPs,
                $pidPathForPs,
            );
            $shellCommand = sprintf(
                'powershell -NoProfile -ExecutionPolicy Bypass -Command %s',
                escapeshellarg($psScript),
            );
        } else {
            $runnerScript = sprintf(
                '%s; printf "%%s" "$?" > %s',
                $downloadCommand,
                escapeshellarg($exitPath),
            );

            if ($this->isUnixCommandAvailable('setsid')) {
                $shellCommand = sprintf(
                    'setsid sh -c %s > %s 2>&1 < /dev/null & echo $! > %s',
                    escapeshellarg($runnerScript),
                    escapeshellarg($logPath),
                    escapeshellarg($pidPath),
                );
            } else {
                $shellCommand = sprintf(
                    'sh -c %s > %s 2>&1 < /dev/null & echo $! > %s',
                    escapeshellarg($runnerScript),
                    escapeshellarg($logPath),
                    escapeshellarg($pidPath),
                );
            }
        }

        $spawnOutput = [];
        $spawnExitCode = 0;
        exec($shellCommand, $spawnOutput, $spawnExitCode);

        if ($spawnExitCode !== 0) {
            @file_put_contents(
                $spawnLogPath,
                sprintf(
                    "spawnExitCode=%d\ncommand=%s\noutput=%s\n",
                    $spawnExitCode,
                    $shellCommand,
                    implode("\n", $spawnOutput),
                ),
            );

            return [
                'success' => false,
                'message' => 'Cannot start yt-dlp job.',
                'jobId' => null,
                'offset' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => 'Download started.',
            'jobId' => $jobId,
            'offset' => 0,
        ];
    }

    /**
     * @return array{formatSelector: string, recodeVideo: bool}
     */
    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     done: bool,
     *     isSuccess: bool,
     *     isConverting: bool,
     *     stage: string,
     *     outputChunk: string,
     *     offset: int,
     *     progress: float|null
     * }
     */
    public function status(string $jobId, int $offset): array
    {
        $logPath = $this->jobsDirectory . '/' . $jobId . '.log';
        $exitPath = $this->jobsDirectory . '/' . $jobId . '.exit';

        if (!is_file($logPath)) {
            if (!file_exists($exitPath)) {
                return [
                    'success' => true,
                    'message' => 'Preparing downloader...',
                    'done' => false,
                    'isSuccess' => false,
                    'isConverting' => false,
                    'stage' => 'starting',
                    'outputChunk' => '',
                    'offset' => $offset < 0 ? 0 : $offset,
                    'progress' => null,
                ];
            }

            return [
                'success' => false,
                'message' => 'Job log was not found.',
                'done' => true,
                'isSuccess' => false,
                'isConverting' => false,
                'stage' => 'failed',
                'outputChunk' => '',
                'offset' => 0,
                'progress' => null,
            ];
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $chunk = file_get_contents(
            filename: $logPath,
            use_include_path: false,
            context: null,
            offset: $offset,
        );

        if (!is_string($chunk)) {
            $chunk = '';
        }

        $fullLog = file_get_contents($logPath);
        if (!is_string($fullLog)) {
            $fullLog = '';
        }

        $done = file_exists($exitPath);
        $isSuccess = false;
        $isConverting = !$done && $this->isConvertingLog($fullLog);
        $stage = 'downloading';
        $message = 'Downloading...';

        if ($isConverting) {
            $stage = $this->hasFfmpegTimeProgress($fullLog) ? 'converting' : 'starting-ffmpeg';
            $message = $stage === 'starting-ffmpeg' ? 'Starting ffmpeg...' : 'Converting...';
        }

        if ($done) {
            $exitCode = trim((string)file_get_contents($exitPath));
            $isSuccess = $exitCode === '0';
            $stage = $isSuccess ? 'completed' : 'failed';
            $message = match ($exitCode) {
                '0' => 'Download completed.',
                '130' => 'Download stopped.',
                default => 'Download failed.',
            };
        }

        return [
            'success' => true,
            'message' => $message,
            'done' => $done,
            'isSuccess' => $isSuccess,
            'isConverting' => $isConverting,
            'stage' => $stage,
            'outputChunk' => $chunk,
            'offset' => $offset + strlen($chunk),
            'progress' => $isConverting ? $this->extractConvertingProgress($fullLog) : $this->extractProgress($fullLog),
        ];
    }

    private function isConvertingLog(string $log): bool
    {
        $hasConvertedDestination = str_contains($log, 'Destination:')
            && (str_contains($log, '.mp3') || str_contains($log, '.mp4'));

        return str_contains($log, '[ExtractAudio]')
            || str_contains($log, '[VideoConvertor]')
            || str_contains($log, 'Deleting original file')
            || $hasConvertedDestination;
    }

    /**
     * @return array{formatSelector: string, recodeVideo: bool}
     */
    private function resolveMp4DownloadPlan(
        string $normalizedFormat,
    ): array {
        $maxHeight = null;
        if (str_starts_with($normalizedFormat, self::PRESET_MP4 . ':')) {
            $parts = explode(':', $normalizedFormat);
            $candidateHeight = array_key_exists(2, $parts) ? (int)$parts[2] : 0;

            if (in_array($candidateHeight, self::MP4_HEIGHTS, true)) {
                $maxHeight = $candidateHeight;
            }
        }

        if ($maxHeight !== null) {
            return [
                'formatSelector' => sprintf(
                    'best[ext=mp4][vcodec!=none][acodec!=none][height<=%d]/bestvideo*[ext=mp4][height<=%d]+bestaudio[ext=m4a]/bestvideo*[ext=mp4][height<=%d]+bestaudio[ext=aac]/bestvideo*[height<=%d]+bestaudio/best[height<=%d]/best',
                    $maxHeight,
                    $maxHeight,
                    $maxHeight,
                    $maxHeight,
                    $maxHeight,
                ),
                'recodeVideo' => true,
            ];
        }

        return [
            'formatSelector' => 'best[ext=mp4][vcodec!=none][acodec!=none]/bestvideo*[ext=mp4]+bestaudio[ext=m4a]/bestvideo*[ext=mp4]+bestaudio[ext=aac]/bestvideo+bestaudio/best',
            'recodeVideo' => true,
        ];
    }


    public function clearJobCache(): int
    {
        if (!is_dir($this->jobsDirectory)) {
            return 0;
        }

        $entries = glob($this->jobsDirectory . '/*');
        if (!is_array($entries)) {
            return 0;
        }

        $removed = 0;

        foreach ($entries as $entry) {
            if (!is_file($entry)) {
                continue;
            }

            if (unlink($entry)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function stop(string $jobId): array
    {
        $pidPath = $this->jobsDirectory . '/' . $jobId . '.pid';
        $exitPath = $this->jobsDirectory . '/' . $jobId . '.exit';
        $logPath = $this->jobsDirectory . '/' . $jobId . '.log';

        $pid = $this->readPid($pidPath);
        if ($pid === null) {
            if (file_exists($exitPath)) {
                return [
                    'success' => true,
                    'message' => 'Download already completed.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Cannot stop job: process id was not found.',
            ];
        }

        $stopped = $this->terminateProcessTree($pid);

        if (!$stopped && PHP_OS_FAMILY !== 'Windows') {
            $stopped = $this->killResidualMediaProcesses();
        }

        if (!file_exists($exitPath)) {
            @file_put_contents($exitPath, '130');
        }

        @file_put_contents($logPath, "\n[app] Stop requested by user.\n", FILE_APPEND);

        return [
            'success' => $stopped,
            'message' => $stopped ? 'Download stopped.' : 'Failed to stop download process.',
        ];
    }

    public function stopAllActiveJobs(): int
    {
        if (!is_dir($this->jobsDirectory)) {
            return 0;
        }

        $pidFiles = glob($this->jobsDirectory . '/*.pid');
        if (!is_array($pidFiles)) {
            return 0;
        }

        $stopped = 0;
        foreach ($pidFiles as $pidFile) {
            if (!is_file($pidFile)) {
                continue;
            }

            $jobId = basename($pidFile, '.pid');
            $result = $this->stop($jobId);
            if ($result['success']) {
                $stopped++;
            }
        }

        return $stopped;
    }

    private function readPid(string $pidPath): ?int
    {
        if (!is_file($pidPath)) {
            return null;
        }

        $raw = trim((string)file_get_contents($pidPath));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $pid = (int)$raw;

        return $pid > 0 ? $pid : null;
    }

    private function terminateProcessTree(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!$this->isWindowsProcessRunning($pid)) {
                return true;
            }

            $output = [];
            $exitCode = 0;
            exec(sprintf('taskkill /PID %d /T /F 2>NUL', $pid), $output, $exitCode);

            return $exitCode === 0 || !$this->isWindowsProcessRunning($pid);
        }

        if (!$this->isUnixProcessRunning($pid)) {
            return true;
        }

        $descendants = $this->collectUnixDescendantPids($pid);

        exec(sprintf('kill -TERM -%d 2>/dev/null || true', $pid));
        foreach ($descendants as $childPid) {
            exec(sprintf('kill -TERM %d 2>/dev/null || true', $childPid));
        }
        exec(sprintf('kill -TERM %d 2>/dev/null || true', $pid));

        \usleep(300000);

        if (!$this->isUnixProcessRunning($pid) && !$this->hasAnyRunningUnixProcess($descendants)) {
            return true;
        }

        exec(sprintf('kill -KILL -%d 2>/dev/null || true', $pid));
        foreach ($descendants as $childPid) {
            exec(sprintf('kill -KILL %d 2>/dev/null || true', $childPid));
        }
        exec(sprintf('kill -KILL %d 2>/dev/null || true', $pid));

        \usleep(150000);

        if (!$this->isUnixProcessRunning($pid) && !$this->hasAnyRunningUnixProcess($descendants)) {
            return true;
        }

        return $this->killResidualMediaProcesses();
    }

    /**
     * @phpstan-impure
     * @return int[]
     */
    private function collectUnixDescendantPids(int $rootPid): array
    {
        if ($rootPid <= 0 || PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        $queue = [$rootPid];
        $visited = [$rootPid => true];
        $descendants = [];

        while ($queue !== []) {
            $parentPid = array_shift($queue);

            $children = [];
            $exitCode = 0;
            exec(sprintf('pgrep -P %d 2>/dev/null', $parentPid), $children, $exitCode);
            if ($children === []) {
                continue;
            }

            foreach ($children as $childRaw) {
                $childPid = (int)trim($childRaw);
                if ($childPid <= 0 || array_key_exists($childPid, $visited)) {
                    continue;
                }

                $visited[$childPid] = true;
                $descendants[] = $childPid;
                $queue[] = $childPid;
            }
        }

        return $descendants;
    }

    /**
     * @phpstan-impure
     * @param int[] $pids
     */
    private function hasAnyRunningUnixProcess(array $pids): bool
    {
        foreach ($pids as $pid) {
            if ($pid > 0 && $this->isUnixProcessRunning($pid)) {
                return true;
            }
        }

        return false;
    }

    /** @phpstan-impure */
    private function isUnixCommandAvailable(string $command): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        if ($command === '' || preg_match('/[^A-Za-z0-9_.-]/', $command) === 1) {
            return false;
        }

        $output = [];
        $exitCode = 0;
        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)), $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }

    /** @phpstan-impure */
    private function killResidualMediaProcesses(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $commands = [
            "pkill -TERM -f '[y]t-dlp' 2>/dev/null",
            "pkill -TERM -f '[f]fmpeg' 2>/dev/null",
            "pkill -TERM -f '[f]fprobe' 2>/dev/null",
        ];

        foreach ($commands as $command) {
            exec($command);
        }

        \usleep(300000);

        $killCommands = [
            "pkill -KILL -f '[y]t-dlp' 2>/dev/null",
            "pkill -KILL -f '[f]fmpeg' 2>/dev/null",
            "pkill -KILL -f '[f]fprobe' 2>/dev/null",
        ];

        foreach ($killCommands as $command) {
            exec($command);
        }

        return true;
    }

    /** @phpstan-impure */
    private function isWindowsProcessRunning(int $pid): bool
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('tasklist /FI "PID eq %d" /NH 2>NUL', $pid), $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return false;
        }

        $line = trim($output[0]);

        return $line !== '' && !str_contains($line, 'No tasks are running');
    }

    /** @phpstan-impure */
    private function isUnixProcessRunning(int $pid): bool
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('kill -0 %d 2>/dev/null', $pid), $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * @return array{success: bool, message: string, missing: list<string>, installCommand: string}
     */
    public function checkRuntimeDependencies(): array
    {
        $toolsDirectory = $this->resolveToolsDirectory();

        $missing = [];
        $broken = [];

        $ytDlpBinary = $this->resolveYtDlpBinaryPath($toolsDirectory);
        if ($ytDlpBinary === null) {
            $missing[] = 'yt-dlp';
        } elseif (!$this->isOperationalYtDlp($ytDlpBinary)) {
            $broken[] = 'yt-dlp';
        }

        $ffmpegBinary = $this->resolveAuxBinaryPath('ffmpeg', $toolsDirectory);
        if ($ffmpegBinary === null) {
            $missing[] = 'ffmpeg';
        } elseif (!$this->isOperationalAuxBinary($ffmpegBinary)) {
            $broken[] = 'ffmpeg';
        }

        $ffprobeBinary = $this->resolveAuxBinaryPath('ffprobe', $toolsDirectory);
        if ($ffprobeBinary === null) {
            $missing[] = 'ffprobe';
        } elseif (!$this->isOperationalAuxBinary($ffprobeBinary)) {
            $broken[] = 'ffprobe';
        }

        if ($missing === [] && $broken === []) {
            return [
                'success' => true,
                'message' => 'Runtime dependencies are available.',
                'missing' => [],
                'installCommand' => '',
            ];
        }

        $required = array_values(array_unique([...$missing, ...$broken]));
        $installCommand = $this->suggestInstallCommand($required, $broken);

        $parts = [];
        if ($missing !== []) {
            $parts[] = sprintf('Missing runtime tools: %s', implode(', ', $missing));
        }

        if ($broken !== []) {
            $parts[] = sprintf('Runtime tools found but not operational: %s', implode(', ', $broken));
        }

        $message = implode('. ', $parts);

        return [
            'success' => false,
            'message' => $message,
            'missing' => $required,
            'installCommand' => $installCommand,
        ];
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $path));
        }
    }

    private function resolveYtDlpBinary(?string $toolsDirectory): ?string
    {
        $candidate = $this->resolveYtDlpBinaryPath($toolsDirectory);

        if ($candidate === null || !$this->isOperationalYtDlp($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function resolveYtDlpBinaryPath(?string $toolsDirectory): ?string
    {
        $override = getenv('YTDLP_BIN');
        if (is_string($override) && $override !== '' && $this->isUsableBinary($override)) {
            return $override;
        }

        if (is_string($toolsDirectory) && $toolsDirectory !== '') {
            $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
            $bundled = $toolsDirectory . '/yt-dlp' . $ext;

            if ($this->isUsableBinary($bundled)) {
                return $bundled;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (class_exists(Phar::class, false)) {
                $running = Phar::running(false);
                if ($running !== '') {
                    $candidate = str_replace('/', '\\', dirname($running)) . '\\yt-dlp.exe';
                    if ($this->isUsableBinary($candidate)) {
                        return $candidate;
                    }
                }
            }

            foreach (['yt-dlp.exe', 'yt-dlp'] as $candidate) {
                if ($this->isUsableBinary($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        foreach ($this->preferredUnixBinaryCandidates('yt-dlp') as $candidate) {
            if ($this->isUsableBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }


    private function resolveAuxBinaryPath(string $binaryName, ?string $toolsDirectory): ?string
    {
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        if (is_string($toolsDirectory) && $toolsDirectory !== '') {
            $bundled = $toolsDirectory . '/' . $binaryName . $ext;
            if ($this->isUsableBinary($bundled)) {
                return $bundled;
            }
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [$binaryName . $ext, $binaryName]
            : $this->preferredUnixBinaryCandidates($binaryName);

        foreach ($candidates as $candidate) {
            if ($this->isUsableBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function preferredUnixBinaryCandidates(string $binaryName): array
    {
        $candidates = [];

        $fromPath = $this->resolveBinaryFromPath($binaryName);
        if ($fromPath !== null) {
            $candidates[] = $fromPath;
        }

        $formula = $this->brewFormulaForBinary($binaryName);
        if ($formula !== null) {
            $brewPrefix = $this->resolveBrewPrefix($formula);
            if ($brewPrefix !== null) {
                $candidates[] = $brewPrefix . '/bin/' . $binaryName;
            }

            $cellarCandidates = glob('/opt/homebrew/Cellar/' . $formula . '/*/bin/' . $binaryName);
            if (is_array($cellarCandidates)) {
                rsort($cellarCandidates);
                foreach ($cellarCandidates as $cellarCandidate) {
                    $candidates[] = $cellarCandidate;
                }
            }
        }

        foreach (
            [
                '/opt/homebrew/bin/' . $binaryName,
                '/usr/local/bin/' . $binaryName,
                '/opt/local/bin/' . $binaryName,
                $binaryName,
            ] as $fallback
        ) {
            $candidates[] = $fallback;
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    private function resolveBinaryFromPath(string $binaryName): ?string
    {
        $output = [];
        $exitCode = 0;

        exec(
            sprintf('command -v %s 2>/dev/null', escapeshellarg($binaryName)),
            $output,
            $exitCode,
        );

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $resolved = trim($output[0]);

        return $resolved !== '' ? $resolved : null;
    }

    private function brewFormulaForBinary(string $binaryName): ?string
    {
        if ($binaryName === 'ffprobe') {
            return 'ffmpeg';
        }

        if ($binaryName === 'yt-dlp' || $binaryName === 'ffmpeg') {
            return $binaryName;
        }

        return null;
    }

    private function resolveBrewPrefix(string $formula): ?string
    {
        $output = [];
        $exitCode = 0;

        exec(
            sprintf('brew --prefix %s 2>/dev/null', escapeshellarg($formula)),
            $output,
            $exitCode,
        );

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $prefix = trim($output[0]);

        return $prefix !== '' ? $prefix : null;
    }

    /**
     * @param list<string> $missing
     * @param list<string> $broken
     */
    private function suggestInstallCommand(array $missing, array $broken = []): string
    {
        $needsYtDlp = in_array('yt-dlp', $missing, true);
        $needsFfmpeg = in_array('ffmpeg', $missing, true) || in_array('ffprobe', $missing, true);
        $brokenYtDlp = in_array('yt-dlp', $broken, true);
        $brokenFfmpeg = in_array('ffmpeg', $broken, true) || in_array('ffprobe', $broken, true);

        if (PHP_OS_FAMILY === 'Darwin') {
            if ($brokenYtDlp && $brokenFfmpeg) {
                return 'brew install python@3.12 && brew reinstall yt-dlp ffmpeg';
            }

            if ($brokenYtDlp) {
                return 'brew install python@3.12 && brew reinstall yt-dlp';
            }

            if ($brokenFfmpeg) {
                return 'brew reinstall ffmpeg';
            }

            if ($needsYtDlp && $needsFfmpeg) {
                return 'brew install yt-dlp ffmpeg';
            }

            if ($needsYtDlp) {
                return 'brew install yt-dlp';
            }

            return 'brew install ffmpeg';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if ($brokenYtDlp && $brokenFfmpeg) {
                return 'winget uninstall yt-dlp.yt-dlp && winget install -e --id yt-dlp.yt-dlp && winget uninstall Gyan.FFmpeg && winget install -e --id Gyan.FFmpeg';
            }

            if ($brokenYtDlp) {
                return 'winget uninstall yt-dlp.yt-dlp && winget install -e --id yt-dlp.yt-dlp';
            }

            if ($brokenFfmpeg) {
                return 'winget uninstall Gyan.FFmpeg && winget install -e --id Gyan.FFmpeg';
            }

            if ($needsYtDlp && $needsFfmpeg) {
                return 'winget install -e --id yt-dlp.yt-dlp && winget install -e --id Gyan.FFmpeg';
            }

            if ($needsYtDlp) {
                return 'winget install -e --id yt-dlp.yt-dlp';
            }

            return 'winget install -e --id Gyan.FFmpeg';
        }

        if ($brokenYtDlp && $brokenFfmpeg) {
            return 'sudo apt update && sudo apt install --reinstall -y yt-dlp ffmpeg';
        }

        if ($brokenYtDlp) {
            return 'sudo apt update && sudo apt install --reinstall -y yt-dlp';
        }

        if ($brokenFfmpeg) {
            return 'sudo apt update && sudo apt install --reinstall -y ffmpeg';
        }

        if ($needsYtDlp && $needsFfmpeg) {
            return 'sudo apt update && sudo apt install -y yt-dlp ffmpeg';
        }

        if ($needsYtDlp) {
            return 'sudo apt update && sudo apt install -y yt-dlp';
        }

        return 'sudo apt update && sudo apt install -y ffmpeg';
    }

    private function isOperationalYtDlp(string $binaryPath): bool
    {
        return $this->probeBinary(binaryPath: $binaryPath, argument: '--version');
    }

    private function isOperationalAuxBinary(string $binaryPath): bool
    {
        return $this->probeBinary(binaryPath: $binaryPath, argument: '-version');
    }

    private function probeBinary(string $binaryPath, string $argument): bool
    {
        $output = [];
        $exitCode = 0;

        exec(
            command: sprintf('%s %s 2>&1', escapeshellarg($binaryPath), escapeshellarg($argument)),
            output: $output,
            result_code: $exitCode,
        );

        return $exitCode === 0;
    }

    private function resolveToolsDirectory(): ?string
    {
        $toolsOverride = getenv('YTDLP_TOOLS_DIR');
        if (is_string($toolsOverride) && $toolsOverride !== '' && $this->isValidToolsDirectory($toolsOverride)) {
            return $toolsOverride;
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

        if (class_exists(Phar::class, false)) {
            $running = Phar::running(false);
            if ($running !== '') {
                $binaryTools = dirname($running) . '/tools';
                if ($this->isValidToolsDirectory($binaryTools)) {
                    return $binaryTools;
                }
            }
        }

        $argv = $_SERVER['argv'] ?? null;
        $argv0 = null;
        if (is_array($argv) && array_key_exists(0, $argv) && is_string($argv[0])) {
            $argv0 = $argv[0];
        }

        if (!is_string($argv0) || $argv0 === '') {
            return $this->extractEmbeddedToolsFromPhar();
        }

        $binaryTools = dirname($argv0) . '/tools';

        if ($this->isValidToolsDirectory($binaryTools)) {
            return $binaryTools;
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

        $embeddedPaths = $this->resolveEmbeddedPharTools($running);
        if ($embeddedPaths === null) {
            return null;
        }

        $signature = hash_file('sha256', $running);
        if ($signature === false) {
            $signature = preg_replace('/[^A-Za-z0-9_.-]/', '_', $running);

            if (!is_string($signature)) {
                return null;
            }
        }

        $targetDirectory = sprintf('%s/ytdlpgui/tools/%s', rtrim(sys_get_temp_dir(), '/'), $signature);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            return null;
        }

        // Check if tools are already extracted with matching version signature
        if ($this->areToolsExtractedAndValid($targetDirectory, $embeddedPaths)) {
            return $targetDirectory;
        }

        foreach ($embeddedPaths as $name => $embeddedPath) {
            $targetPath = sprintf('%s/%s', $targetDirectory, $name);

            $contents = file_get_contents($embeddedPath);
            if (!is_string($contents) || $contents === '') {
                return null;
            }

            if (file_put_contents($targetPath, $contents) === false) {
                return null;
            }

            if (!chmod($targetPath, 0755)) {
                return null;
            }
        }

        return $targetDirectory;
    }

    /**
     * @param array<string, string> $embeddedPaths
     */
    private function areToolsExtractedAndValid(string $targetDirectory, array $embeddedPaths): bool
    {
        foreach ($embeddedPaths as $name => $embeddedPath) {
            $targetPath = sprintf('%s/%s', $targetDirectory, $name);

            if (!$this->isUsableBinary($targetPath)) {
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
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        foreach (['dist/build/tools', 'var/tools', 'tools'] as $prefix) {
            $embeddedPaths = [
                'yt-dlp' . $ext => sprintf('phar://%s/%s/yt-dlp%s', $running, $prefix, $ext),
                'ffmpeg' . $ext => sprintf('phar://%s/%s/ffmpeg%s', $running, $prefix, $ext),
                'ffprobe' . $ext => sprintf('phar://%s/%s/ffprobe%s', $running, $prefix, $ext),
            ];

            $allExist = true;
            foreach ($embeddedPaths as $embeddedPath) {
                if (!file_exists($embeddedPath)) {
                    $allExist = false;
                    break;
                }
            }

            if ($allExist) {
                return $embeddedPaths;
            }
        }

        return null;
    }

    private function isUsableBinary(string $binaryPath): bool
    {
        $isAbsolute = str_contains($binaryPath, '/') || str_contains($binaryPath, '\\');

        if ($isAbsolute) {
            if (PHP_OS_FAMILY === 'Windows') {
                return is_file($binaryPath) && is_readable($binaryPath);
            }

            return is_file($binaryPath) && is_executable($binaryPath);
        }

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

            return $resolved !== '' && is_file($resolved) && is_readable($resolved);
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

    private function quoteOutputTemplateArgument(string $template): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return escapeshellarg($template);
        }

        // In cmd/batch files '%' triggers env expansion, so keep yt-dlp placeholders via '%%'.
        $escaped = str_replace('%', '%%', $template);

        return '"' . str_replace('"', '""', $escaped) . '"';
    }

    private function missingYtDlpMessage(): string
    {
        return 'yt-dlp binary was not found. Install yt-dlp/ffmpeg/ffprobe and add them to PATH, or set YTDLP_BIN / YTDLP_TOOLS_DIR.';
    }

    private function extractProgress(string $log): ?float
    {
        if ($log === '') {
            return null;
        }

        $matches = [];
        preg_match_all('/\[download]\s+([0-9]+(?:\.[0-9]+)?)%/', $log, $matches);

        if (empty($matches[1])) {
            return null;
        }

        /** @var list<non-empty-string> $percentages */
        $percentages = $matches[1];
        $last = $percentages[count($percentages) - 1];

        return (float)$last;
    }

    private function extractConvertingProgress(string $log): ?float
    {
        if ($log === '') {
            return null;
        }

        $durationMatches = [];
        preg_match('/Duration:\s*([0-9]{2}):([0-9]{2}):([0-9]{2}(?:\.[0-9]+)?)/', $log, $durationMatches);

        if (empty($durationMatches)) {
            return null;
        }

        $durationSeconds = $this->timeToSeconds(
            (int)$durationMatches[1],
            (int)$durationMatches[2],
            (float)$durationMatches[3],
        );

        if ($durationSeconds <= 0.0) {
            return null;
        }

        $timeMatches = [];
        preg_match_all('/time=([0-9]{2}):([0-9]{2}):([0-9]{2}(?:\.[0-9]+)?)/', $log, $timeMatches);

        if (
            empty($timeMatches[1])
            || empty($timeMatches[2])
            || empty($timeMatches[3])
        ) {
            return null;
        }

        $lastIndex = count($timeMatches[1]) - 1;
        $currentSeconds = $this->timeToSeconds(
            (int)$timeMatches[1][$lastIndex],
            (int)$timeMatches[2][$lastIndex],
            (float)$timeMatches[3][$lastIndex],
        );

        if ($currentSeconds <= 0.0) {
            return null;
        }

        $ratio = $currentSeconds / $durationSeconds;
        if ($ratio < 0.0) {
            $ratio = 0.0;
        }

        if ($ratio > 0.995) {
            return 99.5;
        }

        return $ratio * 100.0;
    }

    private function hasFfmpegTimeProgress(string $log): bool
    {
        return preg_match('/time=([0-9]{2}):([0-9]{2}):([0-9]{2}(?:\.[0-9]+)?)/', $log) === 1;
    }

    private function timeToSeconds(int $hours, int $minutes, float $seconds): float
    {
        return ($hours * 3600.0) + ($minutes * 60.0) + $seconds;
    }

    /**
     * @param list<array<array-key, mixed>> $formats
     *
     * @return list<array{id: string, label: string, qualities: list<array{id: string, label: string, formatId: string}>}>
     */
    private function buildDownloadProfiles(array $formats): array
    {
        $profiles = [
            [
                'id' => 'default',
                'label' => 'Default (video + audio merged)',
                'qualities' => [
                    [
                        'id' => 'auto',
                        'label' => 'Auto',
                        'formatId' => self::DEFAULT_FORMAT,
                    ],
                ],
            ],
        ];

        $containers = [];
        $audioContainers = [];

        foreach ($formats as $format) {
            $ext = array_key_exists('ext', $format) && is_string($format['ext'])
                ? trim($format['ext'])
                : '';

            if ($ext === '') {
                continue;
            }

            $hasVideo = array_key_exists('vcodec', $format) && is_string(
                    $format['vcodec'],
                ) && $format['vcodec'] !== 'none';
            $hasAudio = array_key_exists('acodec', $format) && is_string(
                    $format['acodec'],
                ) && $format['acodec'] !== 'none';

            if ($hasVideo) {
                $height = array_key_exists('height', $format) && is_numeric($format['height'])
                    ? (int)$format['height']
                    : 0;

                if (!array_key_exists($ext, $containers)) {
                    $containers[$ext] = [];
                }

                if ($height > 0) {
                    $containers[$ext][$height] = $height;
                }

                continue;
            }

            if (!$hasAudio) {
                continue;
            }

            $abr = array_key_exists('abr', $format) && is_numeric($format['abr'])
                ? (int)$format['abr']
                : 0;

            if (!array_key_exists($ext, $audioContainers)) {
                $audioContainers[$ext] = [];
            }

            if ($abr > 0) {
                $audioContainers[$ext][$abr] = $abr;
            }
        }

        foreach ($containers as $ext => $heights) {
            krsort($heights);

            $audioExt = $this->preferredAudioExtensionForVideoContainer($ext);

            $autoFormat = sprintf(
                'bestvideo*[ext=%s]+bestaudio[ext=%s]',
                $ext,
                $audioExt,
            );

            if ($ext === 'mp4') {
                $autoFormat = 'bestvideo*[ext=mp4]+bestaudio[ext=m4a]/bestvideo*[ext=mp4]+bestaudio[ext=aac]';
            }

            $qualities = [
                [
                    'id' => 'auto',
                    'label' => 'Auto',
                    'formatId' => $autoFormat,
                ],
            ];

            foreach ($heights as $height) {
                $formatId = sprintf(
                    'bestvideo*[ext=%s][height<=%d]+bestaudio[ext=%s]',
                    $ext,
                    $height,
                    $audioExt,
                );

                if ($ext === 'mp4') {
                    $formatId = sprintf(
                        'bestvideo*[ext=mp4][height<=%d]+bestaudio[ext=m4a]/bestvideo*[ext=mp4][height<=%d]+bestaudio[ext=aac]',
                        $height,
                        $height,
                    );
                }

                $qualities[] = [
                    'id' => (string)$height,
                    'label' => sprintf('%dp', $height),
                    'formatId' => $formatId,
                ];
            }

            $profiles[] = [
                'id' => $ext,
                'label' => strtoupper($ext),
                'qualities' => $qualities,
            ];
        }

        foreach ($audioContainers as $ext => $bitrates) {
            krsort($bitrates);

            $qualities = [
                [
                    'id' => 'auto',
                    'label' => 'Auto',
                    'formatId' => sprintf('bestaudio[ext=%s]/bestaudio', $ext),
                ],
            ];

            foreach ($bitrates as $bitrate) {
                $qualities[] = [
                    'id' => (string)$bitrate,
                    'label' => sprintf('%d kbps', $bitrate),
                    'formatId' => sprintf('bestaudio[ext=%s][abr<=%d]/bestaudio', $ext, $bitrate),
                ];
            }

            $profiles[] = [
                'id' => 'audio-' . $ext,
                'label' => sprintf('Audio %s', strtoupper($ext)),
                'qualities' => $qualities,
            ];
        }

        // Always add MP4 conversion preset
        $profiles[] = [
            'id' => 'mp4-video',
            'label' => 'MP4 (convert from best quality)',
            'qualities' => [
                [
                    'id' => 'auto',
                    'label' => 'Auto',
                    'formatId' => self::PRESET_MP4,
                ],
                [
                    'id' => '144',
                    'label' => '144p',
                    'formatId' => self::PRESET_MP4 . ':144',
                ],
                [
                    'id' => '240',
                    'label' => '240p',
                    'formatId' => self::PRESET_MP4 . ':240',
                ],
                [
                    'id' => '360',
                    'label' => '360p',
                    'formatId' => self::PRESET_MP4 . ':360',
                ],
                [
                    'id' => '480',
                    'label' => '480p',
                    'formatId' => self::PRESET_MP4 . ':480',
                ],
                [
                    'id' => '720',
                    'label' => '720p',
                    'formatId' => self::PRESET_MP4 . ':720',
                ],
                [
                    'id' => '1080',
                    'label' => '1080p',
                    'formatId' => self::PRESET_MP4 . ':1080',
                ],
                [
                    'id' => '1440',
                    'label' => '1440p',
                    'formatId' => self::PRESET_MP4 . ':1440',
                ],
                [
                    'id' => '2160',
                    'label' => '2160p',
                    'formatId' => self::PRESET_MP4 . ':2160',
                ],
            ],
        ];

        // Always add MP3 conversion preset
        $profiles[] = [
            'id' => 'mp3-audio',
            'label' => 'MP3 (convert from best audio)',
            'qualities' => [
                [
                    'id' => 'auto',
                    'label' => 'Auto',
                    'formatId' => self::PRESET_MP3,
                ],
                [
                    'id' => '64',
                    'label' => '64 kbps',
                    'formatId' => self::PRESET_MP3 . ':64',
                ],
                [
                    'id' => '128',
                    'label' => '128 kbps',
                    'formatId' => self::PRESET_MP3 . ':128',
                ],
                [
                    'id' => '256',
                    'label' => '256 kbps',
                    'formatId' => self::PRESET_MP3 . ':256',
                ],
                [
                    'id' => '320',
                    'label' => '320 kbps',
                    'formatId' => self::PRESET_MP3 . ':320',
                ],
            ],
        ];

        return $profiles;
    }

    private function normalizeFormatId(string $formatId): string
    {
        $normalized = trim($formatId);

        if ($normalized === '') {
            return self::DEFAULT_FORMAT;
        }

        return $normalized;
    }

    private function preferredAudioExtensionForVideoContainer(string $container): string
    {
        if ($container === 'mp4') {
            return 'm4a';
        }

        return $container;
    }
}
