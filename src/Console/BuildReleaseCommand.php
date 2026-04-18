<?php

declare(strict_types=1);

namespace YtDlpGui\Console;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_get_contents;
use function is_executable;
use function is_string;
use function str_contains;
use function str_starts_with;

#[AsCommand(
    name: 'app:build:release',
    description: 'Builds a release bundle with yt-dlp, ffmpeg, ffprobe and optional Boson artifact.',
)]
final class BuildReleaseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory.', 'dist/release')
            ->addOption(
                name: 'skip-tools',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Skip bundling yt-dlp/ffmpeg/ffprobe (system-dependent release).',
            )
            ->addOption(
                name: 'boson-command',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Optional Boson shell command. Supported placeholders: {projectDir}, {output}.',
                default: '',
            )
            ->addOption(
                name: 'skip-boson',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Skip Boson step and only produce a bundle with external tools.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = dirname(__DIR__, 2);

        $outputOption = $input->getOption('output');
        if (!is_string($outputOption) || $outputOption === '') {
            $io->error('Option --output must be a non-empty string.');

            return Command::INVALID;
        }

        $releaseDir = $this->resolveOutputDir($projectDir, $outputOption);
        $toolsDir = $releaseDir . '/tools';

        $this->ensureDirectory($toolsDir);

        $bundledTools = [];
        $skipTools = (bool)$input->getOption('skip-tools');
        if (!$skipTools) {
            $io->section('Bundling required binaries');

            foreach (['yt-dlp', 'ffmpeg', 'ffprobe'] as $binary) {
                $sourcePath = $this->locateBinary($projectDir, $binary);
                if ($sourcePath === null) {
                    $io->error(
                        sprintf('Binary "%s" was not found in ./dist/build/tools, ./var/tools, ./tools or PATH.', $binary),
                    );
                    $io->note(
                        'Tip: run make bundle-tools-macos first, or run the command inside Docker where these tools are available.',
                    );

                    return Command::FAILURE;
                }

                $targetPath = $toolsDir . '/' . $binary;
                if (!copy($sourcePath, $targetPath)) {
                    $io->error(sprintf('Failed to copy %s from %s.', $binary, $sourcePath));

                    return Command::FAILURE;
                }

                chmod($targetPath, 0755);
                $bundledTools[$binary] = $targetPath;

                $io->writeln(sprintf('<info>%s</info> => %s', $binary, $targetPath));
            }
        } else {
            $io->note('skip-tools enabled: release will use system yt-dlp/ffmpeg/ffprobe if available.');
        }

        $skipBoson = (bool)$input->getOption('skip-boson');
        $commandOption = $input->getOption('boson-command');
        $bosonCommand = is_string($commandOption) ? trim($commandOption) : '';

        if (!$skipBoson && $bosonCommand !== '') {
            $io->section('Running Boson build command');

            $renderedCommand = strtr($bosonCommand, [
                '{projectDir}' => $projectDir,
                '{output}' => $releaseDir,
            ]);

            $io->writeln($renderedCommand);
            passthru($renderedCommand, $exitCode);

            if ($exitCode !== 0) {
                $io->error('Boson build command failed.');

                return Command::FAILURE;
            }
        }

        if (!$skipBoson && $bosonCommand === '') {
            $io->note('Boson command is not set. Bundle is created without a Boson artifact.');
            $io->note('Use --boson-command="vendor/bin/boson ..." to include Boson build step.');
        }

        $manifestBosonCommand = null;
        if (!$skipBoson && $bosonCommand !== '') {
            $manifestBosonCommand = $bosonCommand;
        }

        $manifest = [
            'builtAt' => gmdate(DATE_ATOM),
            'tools' => $bundledTools,
            'bosonCommand' => $manifestBosonCommand,
        ];

        file_put_contents(
            $releaseDir . '/manifest.json',
            (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $io->success(sprintf('Release bundle is ready: %s', $releaseDir));

        return Command::SUCCESS;
    }

    private function locateBinary(string $projectDir, string $binary): ?string
    {
        $projectTool = $projectDir . '/dist/build/tools/' . $binary;
        if (is_file($projectTool) && is_readable($projectTool) && is_executable($projectTool)) {
            return $projectTool;
        }

        $projectTool = $projectDir . '/var/tools/' . $binary;
        if (is_file($projectTool) && is_readable($projectTool) && is_executable($projectTool)) {
            return $projectTool;
        }

        $projectTool = $projectDir . '/tools/' . $binary;
        if (is_file($projectTool) && is_readable($projectTool) && is_executable($projectTool)) {
            return $projectTool;
        }

        $resolved = trim((string)shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        if ($resolved === '') {
            return null;
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        // Reject python launchers for yt-dlp to keep release runtime independent from host python.
        if ($binary === 'yt-dlp') {
            $prefix = file_get_contents($resolved, false, null, 0, 256);
            if (is_string($prefix) && str_starts_with($prefix, '#!') && str_contains($prefix, 'python')) {
                return null;
            }
        }

        return $resolved;
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

    private function resolveOutputDir(string $projectDir, string $option): string
    {
        if (str_starts_with($option, '/')) {
            return rtrim($option, '/');
        }

        return rtrim($projectDir . '/' . $option, '/');
    }
}

