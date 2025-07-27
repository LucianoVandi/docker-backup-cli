FROM php:8.4-cli

# Installa dipendenze di sistema
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    ca-certificates \
    gnupg \
    lsb-release \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Installa Docker più recente dal repository ufficiale
RUN curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Installa Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Installa estensione PHP zip (necessaria per PHPacker)
RUN docker-php-ext-install zip

# Configura PHP
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini \
    && echo "phar.readonly=0" > /usr/local/etc/php/conf.d/phar.ini

# Installa Box (per creare .phar)
RUN curl -L https://github.com/box-project/box/releases/latest/download/box.phar -o /usr/local/bin/box \
    && chmod +x /usr/local/bin/box

# Installa PHPacker (per eseguibili standalone)
RUN curl -L https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64 -o /usr/local/bin/spc \
    && chmod +x /usr/local/bin/spc \
    || echo "⚠️ Static PHP CLI download failed, will use alternative method"

# Installa PHPacker (per eseguibili standalone) via Composer global
RUN composer global require phpacker/phpacker

# Aggiungi Composer global bin al PATH
ENV PATH="${PATH}:/root/.composer/vendor/bin"

# Crea symlink per comodità
RUN ln -sf /root/.composer/vendor/bin/phpacker /usr/local/bin/phpacker

# Crea directory di lavoro
WORKDIR /app

# Script di build
COPY build.sh /usr/local/bin/build.sh
RUN chmod +x /usr/local/bin/build.sh

# Per sviluppo: i file saranno montati come volume
# In modalità sviluppo, usa: docker run -v $(pwd):/app ...

CMD ["bash"]