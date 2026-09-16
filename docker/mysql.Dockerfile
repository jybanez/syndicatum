ARG MYSQL_IMAGE=mysql:8.4
FROM ${MYSQL_IMAGE}

COPY --chmod=755 docker/mysql-entrypoint.sh /usr/local/bin/syndicatum-mysql-entrypoint

ENTRYPOINT ["syndicatum-mysql-entrypoint"]
CMD ["mysqld"]
