FROM php:8.4-cli

# Install system dependencies
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

# Install docker from official repository
RUN curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP zip extension (required by PHPacker)
RUN docker-php-ext-install zip

# Configure PHP
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini \
    && echo "phar.readonly=0" > /usr/local/etc/php/conf.d/phar.ini

# Install Box (for creating .phar)
RUN curl -L https://github.com/box-project/box/releases/latest/download/box.phar -o /usr/local/bin/box \
    && chmod +x /usr/local/bin/box

# Install PHPacker (for standalone executables)
RUN composer global require phpacker/phpacker

# Add Composer global bin to PATH
ENV PATH="${PATH}:/root/.composer/vendor/bin"

# Create symlink for convenience
RUN ln -sf /root/.composer/vendor/bin/phpacker /usr/local/bin/phpacker

WORKDIR /app

# Copy build script and make it executable
COPY build.sh /usr/local/bin/build.sh
RUN chmod +x /usr/local/bin/build.sh

CMD ["bash"]