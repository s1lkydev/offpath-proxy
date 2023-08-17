FROM php:8.2.8-cli-alpine3.18

ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.1.36/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && install-php-extensions openswoole-22.0.0

WORKDIR /app
COPY OffpathProxy.php config.json .

EXPOSE 8080
ENTRYPOINT ["php", "OffpathProxy.php"]
