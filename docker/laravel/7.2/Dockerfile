FROM webdevops/php-apache-dev:7.2

ENV WEB_DOCUMENT_ROOT=/app \
    WEB_DOCUMENT_INDEX=index.php \
    WEB_ALIAS_DOMAIN=*.vm \
    WEB_PHP_TIMEOUT=600 \
    WEB_PHP_SOCKET=""
ENV WEB_PHP_SOCKET=127.0.0.1:9000
ENV WEB_NO_CACHE_PATTERN="\.(css|js|gif|png|jpg|svg|json|xml)$"

RUN set -ex \
    && curl -sL https://deb.nodesource.com/setup_8.x -o nodesource_setup.sh \
    && bash nodesource_setup.sh \
    && apt-get install nodejs \
    && rm nodesource_setup.sh \
    && npm install -g gulp

RUN set -ex \
	&& echo '* * * * * /usr/local/bin/php /app/artisan schedule:run >> /dev/null 2>&1' > /etc/cron.d/laravel-cron

RUN set -ex \
    && chmod 0644 /etc/cron.d/laravel-cron

RUN set -ex \
    && crontab /etc/cron.d/laravel-cron