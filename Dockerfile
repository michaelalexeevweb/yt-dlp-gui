FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    zip \
    libffi-dev \
    pkg-config \
    ffmpeg \
    yt-dlp \
    && docker-php-ext-install ffi \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /application

# Boson runtime relies on FFI; keep it enabled in the container.
RUN echo "ffi.enable=1" > /usr/local/etc/php/conf.d/99-ffi.ini

CMD ["php", "-v"]
