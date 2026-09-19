ARG MYSQL_IMAGE=mysql:5.7.44
FROM ${MYSQL_IMAGE}

# The MySQL 8.4 Oracle Linux image includes MySQL Shell and its private Python
# environment even though the Syndicatum database service runs only mysqld.
# Remove that unused administrative client and apply the exact available fixes
# for the runtime OpenSSL/libevent packages. The legacy 5.7 image has no
# microdnf, so its immutable RC compatibility path remains byte-for-byte based
# on the pinned upstream image plus the wrapper below.
RUN if command -v microdnf >/dev/null 2>&1 && rpm -q mysql-shell >/dev/null 2>&1; then \
        microdnf upgrade -y \
            'libevent-2.1.13-1.el9_8' \
            'openssl-1:3.5.8-1.0.1.el9_8' \
            'openssl-libs-1:3.5.8-1.0.1.el9_8'; \
        microdnf remove -y mysql-shell; \
        microdnf clean all; \
    fi

COPY --chmod=755 docker/mysql-entrypoint.sh /usr/local/bin/syndicatum-mysql-entrypoint

ENTRYPOINT ["syndicatum-mysql-entrypoint"]
CMD ["mysqld"]
