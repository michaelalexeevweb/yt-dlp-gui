SHELL := /bin/sh

PHP ?= php
COMPOSER ?= composer

PLATFORM ?= macos
ARCH ?= arm64
TOOLS_DIR ?= dist/build/tools
TOOLS_TMP_DIR ?= dist/build/tool-downloads
WINDOWS_TOOLS_DIR ?= dist/build/tools-windows
WINDOWS_TOOLS_TMP_DIR ?= dist/build/tool-downloads-windows
KEEP_TOOLS_TMP ?= 0
FORCE_TOOLS_REFRESH ?= 0
BOSON_CONFIG ?= .boson.$(PLATFORM).$(ARCH).json
APP_NAME ?= YtDlpGui
APP_BUNDLE_PATH ?= dist/macos/$(APP_NAME).app
APP_INSTALL_DIR ?= /Applications
ICON_SOURCE ?= resources/AppIcon.png
WINDOWS_RUNTIME_DIR ?= var/windows-runtime/$(ARCH)
WINDOWS_RUNTIME_MANUAL_DIR ?= resources/windows-runtime/$(ARCH)
WINDOWS_RUNTIME_CACHE_DIR ?= var/windows-runtime/cache/$(ARCH)
WINDOWS_RUNTIME_AUTO ?= 1
FORCE_WINDOWS_RUNTIME_REFRESH ?= 0
WINDOWS_FLAVOR ?= portable
MACOS_FLAVOR ?= portable

.PHONY: help prepare-boson-config bundle-tools-macos bundle-tools-windows \
	prepare-windows-runtime \
	dev dev-macos dev-windows dev-linux \
	macos-app install-macos-app \
	release release-macos release-macos-portable release-macos-desktop \
	release-windows release-windows-portable release-windows-desktop release-linux

help:
	@echo "Available targets:"
	@echo "  make dev PLATFORM=<macos|windows|linux> ARCH=<arch>"
	@echo "  make dev-macos"
	@echo "  make dev-windows"
	@echo "  make dev-linux"
	@echo "  make release PLATFORM=<macos|windows|linux> ARCH=<arch>"
	@echo "    Optional for macos: MACOS_FLAVOR=<portable|desktop>"
	@echo "    Optional for windows: WINDOWS_FLAVOR=<portable|desktop>"
	@echo "  make release-macos"
	@echo "  make release-macos-portable"
	@echo "  make release-macos-desktop"
	@echo "  make release-windows"
	@echo "  make release-windows-portable"
	@echo "  make release-windows-desktop"
	@echo "  make release-linux"

prepare-boson-config:
	@mkdir -p "$(dir $(BOSON_CONFIG))"
	@BOSON_PLATFORM="$(PLATFORM)" BOSON_ARCH="$(ARCH)" BOSON_CONFIG_PATH="$(BOSON_CONFIG)" \
	$(PHP) -r '$$configPath = getenv("BOSON_CONFIG_PATH"); $$platform = getenv("BOSON_PLATFORM"); $$arch = getenv("BOSON_ARCH"); $$payload = json_decode((string) file_get_contents("boson.json"), true); if (!is_array($$payload)) { fwrite(STDERR, "Cannot parse boson.json\n"); exit(1); } $$payload["target"] = [["type" => $$platform, "arch" => $$arch]]; file_put_contents($$configPath, (string) json_encode($$payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));'

bundle-tools-windows:
	@if [ "$(PLATFORM)" != "windows" ]; then \
		echo "bundle-tools-windows currently supports PLATFORM=windows only"; \
		exit 1; \
	fi
	@mkdir -p "$(WINDOWS_TOOLS_DIR)"
	@mkdir -p "$(WINDOWS_TOOLS_TMP_DIR)"
	@if [ "$(FORCE_TOOLS_REFRESH)" = "1" ] || [ ! -f "$(WINDOWS_TOOLS_DIR)/yt-dlp.exe" ] || [ ! -f "$(WINDOWS_TOOLS_DIR)/ffmpeg.exe" ]; then \
		echo "Downloading yt-dlp Windows binary..."; \
		curl -fL 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe' -o "$(WINDOWS_TOOLS_DIR)/yt-dlp.exe"; \
		echo "Downloading ffmpeg Windows binary..."; \
		curl -fL 'https://github.com/yt-dlp/FFmpeg-Builds/releases/latest/download/ffmpeg-master-latest-win64-gpl.zip' -o "$(WINDOWS_TOOLS_TMP_DIR)/ffmpeg-win.zip"; \
		mkdir -p "$(WINDOWS_TOOLS_TMP_DIR)/ffmpeg-win"; \
		unzip -oq "$(WINDOWS_TOOLS_TMP_DIR)/ffmpeg-win.zip" -d "$(WINDOWS_TOOLS_TMP_DIR)/ffmpeg-win"; \
		find "$(WINDOWS_TOOLS_TMP_DIR)/ffmpeg-win" -name 'ffmpeg.exe' | head -1 | xargs -I{} cp {} "$(WINDOWS_TOOLS_DIR)/ffmpeg.exe"; \
		find "$(WINDOWS_TOOLS_TMP_DIR)/ffmpeg-win" -name 'ffprobe.exe' | head -1 | xargs -I{} cp {} "$(WINDOWS_TOOLS_DIR)/ffprobe.exe"; \
		if [ "$(KEEP_TOOLS_TMP)" != "1" ]; then rm -rf '$(WINDOWS_TOOLS_TMP_DIR)'; fi; \
		echo "Windows tools are ready in $(WINDOWS_TOOLS_DIR)"; \
	else \
		echo "Windows tools cache is valid in $(WINDOWS_TOOLS_DIR), skipping downloads."; \
	fi

bundle-tools-macos:
	@if [ "$(PLATFORM)" != "macos" ]; then \
		echo "bundle-tools-macos currently supports PLATFORM=macos only"; \
		exit 1; \
	fi
	@mkdir -p "$(TOOLS_DIR)"
	@mkdir -p "$(TOOLS_TMP_DIR)"
	@if [ "$(FORCE_TOOLS_REFRESH)" = "1" ] || [ ! -x "$(TOOLS_DIR)/yt-dlp" ] || [ ! -x "$(TOOLS_DIR)/ffmpeg" ] || [ ! -x "$(TOOLS_DIR)/ffprobe" ]; then \
		echo "Downloading yt-dlp macOS binary..."; \
		curl -fL 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_macos' -o "$(TOOLS_DIR)/yt-dlp"; \
		echo "Downloading ffmpeg and ffprobe macOS archives..."; \
		curl -fL 'https://evermeet.cx/ffmpeg/getrelease/ffmpeg/zip' -o '$(TOOLS_TMP_DIR)/ffmpeg.zip'; \
		curl -fL 'https://evermeet.cx/ffmpeg/getrelease/ffprobe/zip' -o '$(TOOLS_TMP_DIR)/ffprobe.zip'; \
		rm -rf '$(TOOLS_TMP_DIR)/ffmpeg' '$(TOOLS_TMP_DIR)/ffprobe'; \
		mkdir -p '$(TOOLS_TMP_DIR)/ffmpeg' '$(TOOLS_TMP_DIR)/ffprobe'; \
		unzip -oq '$(TOOLS_TMP_DIR)/ffmpeg.zip' -d '$(TOOLS_TMP_DIR)/ffmpeg'; \
		unzip -oq '$(TOOLS_TMP_DIR)/ffprobe.zip' -d '$(TOOLS_TMP_DIR)/ffprobe'; \
		cp '$(TOOLS_TMP_DIR)/ffmpeg/ffmpeg' "$(TOOLS_DIR)/ffmpeg"; \
		cp '$(TOOLS_TMP_DIR)/ffprobe/ffprobe' "$(TOOLS_DIR)/ffprobe"; \
		chmod +x "$(TOOLS_DIR)/yt-dlp" "$(TOOLS_DIR)/ffmpeg" "$(TOOLS_DIR)/ffprobe"; \
		if [ "$(KEEP_TOOLS_TMP)" != "1" ]; then rm -rf '$(TOOLS_TMP_DIR)'; fi; \
		echo "Tools are ready in $(TOOLS_DIR)"; \
	else \
		echo "Tools cache is valid in $(TOOLS_DIR), skipping downloads."; \
	fi

prepare-windows-runtime:
	@if [ ! -x ./vendor/bin/boson ]; then $(COMPOSER) install; fi
	@FORCE_OPTION=""; \
	if [ "$(FORCE_WINDOWS_RUNTIME_REFRESH)" = "1" ]; then FORCE_OPTION="--force"; fi; \
	NO_DOWNLOAD_OPTION=""; \
	if [ "$(WINDOWS_RUNTIME_AUTO)" != "1" ]; then NO_DOWNLOAD_OPTION="--no-download"; fi; \
	$(PHP) bin/console app:prepare:windows-runtime \
		--arch="$(ARCH)" \
		--output="$(WINDOWS_RUNTIME_DIR)" \
		--manual-source="$(WINDOWS_RUNTIME_MANUAL_DIR)" \
		--cache-dir="$(WINDOWS_RUNTIME_CACHE_DIR)" \
		$$FORCE_OPTION \
		$$NO_DOWNLOAD_OPTION

dev: prepare-boson-config
	@if [ ! -x ./vendor/bin/boson ]; then $(COMPOSER) install; fi
	@if [ "$(PLATFORM)" = "macos" ] && ([ ! -x "$(TOOLS_DIR)/yt-dlp" ] || [ ! -x "$(TOOLS_DIR)/ffmpeg" ] || [ ! -x "$(TOOLS_DIR)/ffprobe" ]); then \
		if [ "$(MACOS_FLAVOR)" = "portable" ]; then \
			$(MAKE) bundle-tools-macos PLATFORM=$(PLATFORM) ARCH=$(ARCH); \
		else \
			mkdir -p "$(TOOLS_DIR)"; \
			find "$(TOOLS_DIR)" -mindepth 1 -maxdepth 1 -exec rm -rf {} +; \
		fi; \
	fi
	@if [ "$(PLATFORM)" = "macos" ] && [ "$(MACOS_FLAVOR)" = "desktop" ]; then \
		mkdir -p "$(TOOLS_DIR)"; \
		find "$(TOOLS_DIR)" -mindepth 1 -maxdepth 1 -exec rm -rf {} +; \
	fi
	@if [ "$(PLATFORM)" = "windows" ]; then \
		if [ "$(WINDOWS_FLAVOR)" = "portable" ]; then \
			$(MAKE) prepare-windows-runtime ARCH=$(ARCH); \
		fi; \
	fi
	./vendor/bin/boson compile --config="$(BOSON_CONFIG)"
	@if [ "$(PLATFORM)" = "windows" ]; then \
		BIN_DIR=$$(find dist/build/windows -type f -name "$(APP_NAME).exe" -exec dirname {} \; | head -n 1); \
		if [ -z "$$BIN_DIR" ]; then echo "Windows executable was not found after build."; exit 1; fi; \
		if [ "$(WINDOWS_FLAVOR)" = "portable" ]; then \
			cp "$(WINDOWS_RUNTIME_DIR)/vcruntime140.dll" "$$BIN_DIR/"; \
			cp "$(WINDOWS_RUNTIME_DIR)/vcruntime140_1.dll" "$$BIN_DIR/"; \
			cp "$(WINDOWS_RUNTIME_DIR)/msvcp140.dll" "$$BIN_DIR/"; \
			cp "$(WINDOWS_RUNTIME_DIR)/msvcp140_atomic_wait.dll" "$$BIN_DIR/"; \
			$(MAKE) bundle-tools-windows PLATFORM=$(PLATFORM) ARCH=$(ARCH); \
			mkdir -p "$$BIN_DIR/tools"; \
			cp "$(WINDOWS_TOOLS_DIR)/yt-dlp.exe" "$$BIN_DIR/tools/"; \
			cp "$(WINDOWS_TOOLS_DIR)/ffmpeg.exe" "$$BIN_DIR/tools/"; \
			cp "$(WINDOWS_TOOLS_DIR)/ffprobe.exe" "$$BIN_DIR/tools/"; \
		else \
			rm -f "$$BIN_DIR/vcruntime140.dll" "$$BIN_DIR/vcruntime140_1.dll" "$$BIN_DIR/msvcp140.dll" "$$BIN_DIR/msvcp140_atomic_wait.dll"; \
			rm -rf "$$BIN_DIR/tools"; \
			touch "$$BIN_DIR/.desktop-build"; \
		fi; \
	fi
	@echo "Build ready in dist/build/$(PLATFORM) (macos flavor: $(MACOS_FLAVOR), windows flavor: $(WINDOWS_FLAVOR))"

dev-macos: PLATFORM=macos
dev-macos: ARCH=arm64
dev-macos: dev

dev-windows: PLATFORM=windows
dev-windows: ARCH=amd64
dev-windows: dev

dev-linux: PLATFORM=linux
dev-linux: ARCH=amd64
dev-linux: dev

macos-app: PLATFORM=macos
macos-app: dev
	@TOOLS_SOURCE_DIR_VALUE="$(TOOLS_DIR)"; \
	if [ "$(MACOS_FLAVOR)" = "desktop" ]; then TOOLS_SOURCE_DIR_VALUE="dist/build/tools-empty"; fi; \
	APP_NAME="$(APP_NAME)" APP_BUNDLE_PATH="$(APP_BUNDLE_PATH)" ICON_SOURCE="$(ICON_SOURCE)" TOOLS_SOURCE_DIR="$$TOOLS_SOURCE_DIR_VALUE" ./bin/build-macos-app

install-macos-app: macos-app
	@mkdir -p "$(APP_INSTALL_DIR)"
	@rm -rf "$(APP_INSTALL_DIR)/$(APP_NAME).app"
	cp -R "$(APP_BUNDLE_PATH)" "$(APP_INSTALL_DIR)/$(APP_NAME).app"
	@echo "Installed: $(APP_INSTALL_DIR)/$(APP_NAME).app"

release:
	@mkdir -p dist/release
	@set -e; \
	if [ "$(PLATFORM)" = "macos" ]; then \
		$(MAKE) macos-app ARCH=$(ARCH) MACOS_FLAVOR=$(MACOS_FLAVOR); \
		if [ "$(MACOS_FLAVOR)" = "portable" ]; then \
			ARCHIVE_NAME="$(APP_NAME)-macos-$(ARCH)-portable.zip"; \
		else \
			ARCHIVE_NAME="$(APP_NAME)-macos-$(ARCH)-desktop.zip"; \
		fi; \
		if command -v ditto >/dev/null 2>&1; then \
			ditto -c -k --sequesterRsrc --keepParent "$(APP_BUNDLE_PATH)" "dist/release/$$ARCHIVE_NAME"; \
		elif command -v zip >/dev/null 2>&1; then \
			( cd "$(dir $(APP_BUNDLE_PATH))" && zip -qry "../release/$$ARCHIVE_NAME" "$(notdir $(APP_BUNDLE_PATH))" ); \
		else \
			echo "No archiver found for macOS release: need 'ditto' (macOS) or 'zip' (Docker/Linux)."; \
			exit 1; \
		fi; \
		echo "Release archive: dist/release/$$ARCHIVE_NAME"; \
	elif [ "$(PLATFORM)" = "windows" ]; then \
		$(MAKE) dev PLATFORM=windows ARCH=$(ARCH) WINDOWS_FLAVOR=$(WINDOWS_FLAVOR); \
		BIN_DIR=$$(find dist/build/windows -type f -name "$(APP_NAME).exe" -exec dirname {} \; | head -n 1); \
		if [ -z "$$BIN_DIR" ]; then echo "Windows executable was not found."; exit 1; fi; \
		rm -rf "dist/release/windows/$(ARCH)"; \
		mkdir -p "dist/release/windows/$(ARCH)"; \
		cp -R "$$BIN_DIR"/. "dist/release/windows/$(ARCH)/"; \
		if [ "$(WINDOWS_FLAVOR)" = "portable" ]; then \
			for dll in vcruntime140.dll vcruntime140_1.dll msvcp140.dll msvcp140_atomic_wait.dll; do \
				if [ ! -f "dist/release/windows/$(ARCH)/$$dll" ]; then \
					echo "Missing $$dll in dist/release/windows/$(ARCH)."; \
					exit 1; \
				fi; \
			done; \
			for tool in yt-dlp.exe ffmpeg.exe ffprobe.exe; do \
				if [ ! -f "dist/release/windows/$(ARCH)/tools/$$tool" ]; then \
					echo "Missing tools/$$tool in dist/release/windows/$(ARCH)."; \
					exit 1; \
				fi; \
			done; \
			printf "@echo off\r\nsetlocal\r\ncd /d %%~dp0\r\nset \"RUN_DIR=%%CD%%\"\r\necho %%RUN_DIR%% | findstr /B /C:\"\\\\\" >nul\r\nif %%ERRORLEVEL%%==0 (\r\n  set \"LOCAL_DIR=%%LOCALAPPDATA%%\\$(APP_NAME)\\$(ARCH)\"\r\n  if exist \"%%LOCAL_DIR%%\" rmdir /s /q \"%%LOCAL_DIR%%\"\r\n  mkdir \"%%LOCAL_DIR%%\"\r\n  xcopy \"%%RUN_DIR%%\\*\" \"%%LOCAL_DIR%%\\\" /E /I /H /Y >nul\r\n  cd /d \"%%LOCAL_DIR%%\"\r\n)\r\n\"$(APP_NAME).exe\"\r\nset EXIT_CODE=%%ERRORLEVEL%%\r\nif not \"%%EXIT_CODE%%\"==\"0\" (\r\n  echo.\r\n  echo $(APP_NAME) exited with code %%EXIT_CODE%%.\r\n  echo Startup log: %%TEMP%%\\ytdlpgui_startup_error.log\r\n  pause\r\n)\r\n" > "dist/release/windows/$(ARCH)/Run $(APP_NAME).cmd"; \
			ARCHIVE_NAME="$(APP_NAME)-windows-$(ARCH)-portable.zip"; \
		else \
			rm -f "dist/release/windows/$(ARCH)/vcruntime140.dll" "dist/release/windows/$(ARCH)/vcruntime140_1.dll" "dist/release/windows/$(ARCH)/msvcp140.dll" "dist/release/windows/$(ARCH)/msvcp140_atomic_wait.dll"; \
			rm -rf "dist/release/windows/$(ARCH)/tools"; \
			touch "dist/release/windows/$(ARCH)/.desktop-build"; \
			printf "@echo off\r\nsetlocal\r\ncd /d %%~dp0\r\n\"$(APP_NAME).exe\"\r\nset EXIT_CODE=%%ERRORLEVEL%%\r\nif not \"%%EXIT_CODE%%\"==\"0\" (\r\n  echo.\r\n  echo $(APP_NAME) exited with code %%EXIT_CODE%%.\r\n  echo Startup log: %%TEMP%%\\ytdlpgui_startup_error.log\r\n  pause\r\n)\r\n" > "dist/release/windows/$(ARCH)/Run $(APP_NAME).cmd"; \
			ARCHIVE_NAME="$(APP_NAME)-windows-$(ARCH)-desktop.zip"; \
		fi; \
		(cd dist/release && zip -qr "$$ARCHIVE_NAME" "windows/$(ARCH)"); \
		echo "Release archive: dist/release/$$ARCHIVE_NAME"; \
	elif [ "$(PLATFORM)" = "linux" ]; then \
		$(MAKE) dev PLATFORM=linux ARCH=$(ARCH); \
		BIN_DIR=$$(find dist/build/linux -type f -name "$(APP_NAME)" -exec dirname {} \; | head -n 1); \
		if [ -z "$$BIN_DIR" ]; then echo "Linux binary was not found."; exit 1; fi; \
		rm -rf "dist/release/linux/$(ARCH)"; \
		mkdir -p "dist/release/linux/$(ARCH)"; \
		cp -R "$$BIN_DIR"/. "dist/release/linux/$(ARCH)/"; \
		tar -C dist/release -cJf "dist/release/$(APP_NAME)-linux-$(ARCH).tar.xz" "linux/$(ARCH)"; \
		echo "Release archive: dist/release/$(APP_NAME)-linux-$(ARCH).tar.xz"; \
	else \
		echo "Unsupported PLATFORM=$(PLATFORM). Use macos, windows or linux."; \
		exit 1; \
	fi

release-macos:
	@if [ -z "$$YTDLPGUI_IN_DOCKER" ] && [ "$(NO_DOCKER)" != "1" ]; then \
		docker compose run --rm -e YTDLPGUI_IN_DOCKER=1 php make release PLATFORM=macos ARCH=arm64 MACOS_FLAVOR=portable; \
	else \
		$(MAKE) release PLATFORM=macos ARCH=arm64 MACOS_FLAVOR=portable; \
	fi

release-macos-portable:
	@if [ -z "$$YTDLPGUI_IN_DOCKER" ] && [ "$(NO_DOCKER)" != "1" ]; then \
		docker compose run --rm -e YTDLPGUI_IN_DOCKER=1 php make release PLATFORM=macos ARCH=arm64 MACOS_FLAVOR=portable; \
	else \
		$(MAKE) release PLATFORM=macos ARCH=arm64 MACOS_FLAVOR=portable; \
	fi

release-macos-desktop:
	@if [ -z "$$YTDLPGUI_IN_DOCKER" ] && [ "$(NO_DOCKER)" != "1" ]; then \
		docker compose run --rm -e YTDLPGUI_IN_DOCKER=1 php make release PLATFORM=macos ARCH=arm64 MACOS_FLAVOR=desktop; \
	else \
		$(MAKE) release PLATFORM=macos ARCH=arm64 MACOS_FLAVOR=desktop; \
	fi

release-windows: PLATFORM=windows
release-windows: ARCH=amd64
release-windows: WINDOWS_FLAVOR=portable
release-windows: release

release-windows-portable: PLATFORM=windows
release-windows-portable: ARCH=amd64
release-windows-portable: WINDOWS_FLAVOR=portable
release-windows-portable: release

release-windows-desktop: PLATFORM=windows
release-windows-desktop: ARCH=amd64
release-windows-desktop: WINDOWS_FLAVOR=desktop
release-windows-desktop: release

release-linux: PLATFORM=linux
release-linux: ARCH=amd64
release-linux: release


