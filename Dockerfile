FROM php:8.4-apache

# cURL is required for the JSON provider clients.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Harden the PHP runtime: no version banner, no error output to clients,
# errors go to the log only.
RUN { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'display_startup_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_reporting = E_ALL'; \
        echo 'session.cookie_httponly = 1'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# Application code lives OUTSIDE the web root; only /opt/lastfm/public is served.
WORKDIR /opt/lastfm
COPY . .

# Apply the hardened Apache configuration: server-level directives, plus a
# vhost whose document root is public/ and which denies src/, tests/ and cache/.
RUN set -eux; \
    a2enmod headers >/dev/null; \
    cp docker/apache-hardening.conf /etc/apache2/conf-available/zz-hardening.conf; \
    a2enconf zz-hardening >/dev/null; \
    cp docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf; \
    chmod +x docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/opt/lastfm/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
