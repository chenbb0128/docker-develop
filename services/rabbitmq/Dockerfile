ARG BASE_IMAGE_REGISTRY=
FROM ${BASE_IMAGE_REGISTRY}rabbitmq:alpine

RUN rabbitmq-plugins enable --offline rabbitmq_management

EXPOSE 4369 5671 5672 15671 15672 25672