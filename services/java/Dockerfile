ARG JAVA_VERSION=8
ARG BASE_IMAGE_REGISTRY=
FROM ${BASE_IMAGE_REGISTRY}eclipse-temurin:${JAVA_VERSION}-jdk

LABEL maintainer="Docker Develop"

ARG PUID=1000
ARG PGID=1000
ARG CONTAINER_PROJECT_PATH=/develop
ARG MAVEN_VERSION=3.9.9
ARG MAVEN_MIRROR=https://maven.aliyun.com/repository/public

ENV JAVA_HOME=/opt/java/openjdk \
    MAVEN_HOME=/opt/maven \
    MAVEN_CONFIG=/home/developer/.m2 \
    PATH=/opt/maven/bin:/opt/java/openjdk/bin:${PATH} \
    MAVEN_OPTS="-Dfile.encoding=UTF-8" \
    TZ=Asia/Shanghai

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        unzip \
        zip \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://archive.apache.org/dist/maven/maven-3/${MAVEN_VERSION}/binaries/apache-maven-${MAVEN_VERSION}-bin.tar.gz" -o /tmp/maven.tgz \
    && mkdir -p /opt/maven \
    && tar -xzf /tmp/maven.tgz -C /opt/maven --strip-components=1 \
    && rm -f /tmp/maven.tgz \
    && mkdir -p /usr/share/maven/ref \
    && printf '%s\n' \
        '<?xml version="1.0" encoding="UTF-8"?>' \
        '<settings xmlns="http://maven.apache.org/SETTINGS/1.2.0"' \
        '          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' \
        '          xsi:schemaLocation="http://maven.apache.org/SETTINGS/1.2.0 https://maven.apache.org/xsd/settings-1.2.0.xsd">' \
        '  <mirrors>' \
        '    <mirror>' \
        '      <id>aliyun-public</id>' \
        '      <mirrorOf>*</mirrorOf>' \
        '      <name>Aliyun Maven</name>' \
        "      <url>${MAVEN_MIRROR}</url>" \
        '    </mirror>' \
        '  </mirrors>' \
        '</settings>' \
        > /usr/share/maven/ref/settings.xml

RUN set -eux; \
    if getent group "${PGID}" >/dev/null; then \
        GROUP_NAME="$(getent group "${PGID}" | cut -d: -f1)"; \
    else \
        groupadd -g "${PGID}" developer; \
        GROUP_NAME=developer; \
    fi; \
    if id -u developer >/dev/null 2>&1; then \
        usermod -u "${PUID}" -o -g "${GROUP_NAME}" -s /bin/bash developer || usermod -g "${GROUP_NAME}" -s /bin/bash developer; \
        usermod -d /home/developer developer || true; \
        mkdir -p /home/developer; \
    elif getent passwd "${PUID}" >/dev/null; then \
        EXISTING_USER="$(getent passwd "${PUID}" | cut -d: -f1)"; \
        usermod -l developer "${EXISTING_USER}"; \
        if [ -d "/home/${EXISTING_USER}" ] && [ ! -e /home/developer ]; then \
            usermod -d /home/developer -m developer; \
        else \
            mkdir -p /home/developer; \
            usermod -d /home/developer developer || true; \
        fi; \
        usermod -g "${GROUP_NAME}" -s /bin/bash developer; \
    else \
        useradd -u "${PUID}" -g "${GROUP_NAME}" -m -d /home/developer -s /bin/bash developer; \
    fi; \
    mkdir -p "${CONTAINER_PROJECT_PATH}" /home/developer/.m2 /home/developer/.gradle; \
    cp /usr/share/maven/ref/settings.xml /home/developer/.m2/settings.xml; \
    chown -R developer:"${GROUP_NAME}" "${CONTAINER_PROJECT_PATH}" /home/developer

WORKDIR ${CONTAINER_PROJECT_PATH}
USER developer

CMD ["tail", "-f", "/dev/null"]