FROM quay.io/nadyita/alpine:3.18

LABEL maintainer="nadyita@hodorraid.org" \
      description="self-sustaining docker image to run sync pork-history change"

ENTRYPOINT ["/sbin/tini", "-g", "--"]

CMD ["/usr/bin/php82", "/syncer/src/main.php"]

RUN apk --no-cache add \
    php82-cli \
    php82-phar \
    php82-sockets \
    php82-pdo \
    php82-pdo_mysql \
    php82-mbstring \
    php82-ctype \
    php82-json \
    php82-posix \
    tini \
    jemalloc \
    && \
    adduser -h /syncer -s /bin/false -D -H syncer && \
    mkdir -p /syncer/src && \
    chown -R syncer:syncer /syncer

COPY --chown=syncer:syncer composer.lock composer.json /syncer/
COPY --chown=syncer:syncer src/main.php /syncer/src/

ENV LD_PRELOAD=libjemalloc.so.2

RUN wget -O /usr/bin/composer https://getcomposer.org/composer-2.phar && \
    apk --no-cache add \
        sudo \
    && \
    cd /syncer && \
    sudo -u syncer php82 /usr/bin/composer install --no-dev --no-interaction --no-progress -q && \
    sudo -u syncer php82 /usr/bin/composer dumpautoload --no-dev --optimize --no-interaction -q && \
    sudo -u syncer php82 /usr/bin/composer clear-cache -q && \
    rm -f /usr/bin/composer && \
    apk del --no-cache sudo

USER syncer

WORKDIR /syncer
