# Nextcloud (Apache) for OpenShift's default restricted-v2 SCC: runs as the
# arbitrary, non-root UID the namespace assigns (always in group 0), with all
# capabilities dropped.
#
# NEXTCLOUD_TAG must be the version you run now or the next major at most:
# the entrypoint refuses to downgrade or to skip a major version.
ARG NEXTCLOUD_TAG=33-apache
FROM docker.io/library/nextcloud:${NEXTCLOUD_TAG}

# Ports below 1024 need NET_BIND_SERVICE, which restricted-v2 drops.
RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

# The entrypoint writes this file when REDIS_HOST is set. Creating it up front
# avoids making the whole conf.d directory writable.
RUN touch /usr/local/etc/php/conf.d/redis-session.ini \
 && chgrp 0 /usr/local/etc/php/conf.d/redis-session.ini \
 && chmod 0660 /usr/local/etc/php/conf.d/redis-session.ini

EXPOSE 8080
