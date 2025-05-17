FROM php:8.2-cli

# نصب curl و unzip و سایر نیازمندی‌ها
RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    git \
    libzip-dev \
    && docker-php-ext-install zip

WORKDIR /app
COPY . /app

CMD ["php", "-S", "0.0.0.0:80", "bot.php"]
