# YtDlpGui

[![MIT License](https://img.shields.io/github/license/michaelalexeevweb/yt-dlp-gui)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/yt-dlp-gui/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/yt-dlp-gui/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/yt-dlp-gui)](https://packagist.org/packages/michaelalexeevweb/yt-dlp-gui)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/yt-dlp-gui)](https://packagist.org/packages/michaelalexeevweb/yt-dlp-gui)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/yt-dlp-gui)](https://packagist.org/packages/michaelalexeevweb/yt-dlp-gui)

Simple PHP GUI for `yt-dlp`.

Build target now: macOS only.

## Requirements

- PHP 8.4+
- `ext-ffi`
- Boson compiler
- `yt-dlp`, `ffmpeg`, `ffprobe` (bundled in portable release)

## Releases

Publish 2 assets in GitHub Release:

- `YtDlpGui-macos-arm64-portable.zip` - app + bundled tools (`yt-dlp`, `ffmpeg`, `ffprobe`)
- `YtDlpGui-macos-arm64-desktop.zip` - app without bundled tools (user installs tools)

Build commands (local, no Docker):

```bash
make release-macos-portable
make release-macos-desktop
```

Outputs to `dist/release/`.

## Work locally

Install dependencies:

```bash
composer install
```

Run dev build (local Boson):

```bash
make dev-macos
```
