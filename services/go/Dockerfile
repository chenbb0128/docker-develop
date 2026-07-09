ARG GO_VERSION=1.25
FROM golang:${GO_VERSION}-bookworm

LABEL maintainer="Docker Develop"

ARG GOPROXY=https://goproxy.cn,direct
ARG PUID=1000
ARG PGID=1000
ARG CONTAINER_PROJECT_PATH=/develop
ARG INSTALL_DELVE=false

ENV GO111MODULE=on \
    GOPROXY=${GOPROXY} \
    CGO_ENABLED=1 \
    PATH=/go/bin:/usr/local/go/bin:${PATH}

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        make \
        gcc \
        g++ \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

RUN if [ "${INSTALL_DELVE}" = "true" ]; then go install github.com/go-delve/delve/cmd/dlv@latest; fi

RUN groupadd -g ${PGID} developer \
    && useradd -u ${PUID} -g developer -m developer -s /bin/bash \
    && mkdir -p ${CONTAINER_PROJECT_PATH} /go/pkg/mod /home/developer/.cache/go-build \
    && chown -R developer:developer ${CONTAINER_PROJECT_PATH} /go /home/developer

WORKDIR ${CONTAINER_PROJECT_PATH}
USER developer

CMD ["tail", "-f", "/dev/null"]
