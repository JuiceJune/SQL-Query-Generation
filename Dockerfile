FROM php:8.3-cli

RUN docker-php-ext-install mysqli

COPY . /app

WORKDIR /app

CMD ["php", "test.php"]

