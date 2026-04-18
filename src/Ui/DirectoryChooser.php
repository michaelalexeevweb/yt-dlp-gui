<?php

declare(strict_types=1);

namespace YtDlpGui\Ui;

use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function sprintf;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

final readonly class DirectoryChooser
{
    public function choose(): ?string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            return $this->chooseMacOs();
        }

        if ($os === 'Linux') {
            return $this->chooseLinux();
        }

        if ($os === 'Windows') {
            return $this->chooseWindows();
        }

        return null;
    }

    private function chooseMacOs(): ?string
    {
        $script = 'osascript'
            . ' -e "tell application \"Finder\" to activate"'
            . ' -e "POSIX path of (choose folder with prompt \"Select download folder:\")"';

        $output = [];
        $exitCode = 0;
        exec($script, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        /** @var string $directory */
        $directory = trim($output[0]);

        return is_dir($directory) ? $directory : null;
    }

    private function chooseLinux(): ?string
    {
        if ($this->commandExists('zenity')) {
            return $this->chooseLinuxZenity();
        }

        if ($this->commandExists('kdialog')) {
            return $this->chooseLinuxKdialog();
        }

        return null;
    }

    private function chooseLinuxZenity(): ?string
    {
        $script = 'zenity --file-selection --directory --title="Select download folder"';

        $output = [];
        $exitCode = 0;
        exec($script, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        /** @var string $directory */
        $directory = trim($output[0]);

        return is_dir($directory) ? $directory : null;
    }

    private function chooseLinuxKdialog(): ?string
    {
        $script = 'kdialog --getexistingdirectory ~ --title "Select download folder"';

        $output = [];
        $exitCode = 0;
        exec($script, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        /** @var string $directory */
        $directory = trim($output[0]);

        return is_dir($directory) ? $directory : null;
    }

    private function chooseWindows(): ?string
    {
        $tempFile = sprintf('%s/folder_choice_%s.txt', sys_get_temp_dir(), uniqid('', true));

        $vbscript = <<<'VBS'
            Set shell = CreateObject("Shell.Application")
            Set folder = shell.BrowseForFolder(0, "Select download folder:", 0, 0)
            If Not folder Is Nothing Then
                CreateObject("Scripting.FileSystemObject").CreateTextFile(WScript.Arguments(0)).Write folder.Self.Path
            End If
            VBS;

        $vbFile = sprintf('%s/folder_dialog_%s.vbs', sys_get_temp_dir(), uniqid('', true));
        file_put_contents($vbFile, $vbscript);

        $command = sprintf('cscript %s %s', escapeshellarg($vbFile), escapeshellarg($tempFile));

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if (!is_file($tempFile)) {
            return null;
        }

        $content = file_get_contents($tempFile);
        $directory = trim($content !== false ? $content : '');

        @unlink($tempFile);
        @unlink($vbFile);

        return is_dir($directory) ? $directory : null;
    }

    private function commandExists(string $command): bool
    {
        $checkCommand = sprintf('command -v %s >/dev/null 2>&1', escapeshellarg($command));
        exec($checkCommand, $output, $exitCode);

        return $exitCode === 0;
    }
}

