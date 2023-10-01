# syntax = docker/dockerfile:1.4
ARG PHP_VERSION
ARG CPU_PREFIX

FROM golang:1.21-alpine AS build
COPY --link ./ /opt/rr
WORKDIR /opt/rr
RUN GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o bootstrap-amd64 main.go plugin.go # -tags lambda.norpc
RUN GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -o bootstrap-arm64 main.go plugin.go # -tags lambda.norpc

# This image only contains what's going in the layer zip file,
# i.e. the files specific to the console layer.
# It's used to create the layer zip file.
FROM alpine:3.14 AS rr-http-zip
# Overwrite the "function" bootstrap
COPY --link --from=build /opt/rr/bootstrap-amd64 /opt/bootstrap
# Copy files to /var/runtime to support deploying as a Docker image
COPY --link --from=build /opt/rr/bootstrap-amd64 /var/runtime/bootstrap

FROM alpine:3.14 AS arm-rr-http-zip
# Overwrite the "function" bootstrap
COPY --link --from=build /opt/rr/bootstrap-arm64 /opt/bootstrap
# Copy files to /var/runtime to support deploying as a Docker image
COPY --link --from=build /opt/rr/bootstrap-arm64 /var/runtime/bootstrap


# This image is published to Docker Hub and replicates Lambda
FROM bref/arm-php-$PHP_VERSION AS arm-rr-http

# Copy the console layer into /opt
COPY --link --from=arm-rr-http-zip /opt /opt
COPY --link --from=arm-rr-http-zip /var/runtime/bootstrap /var/runtime/bootstrap

FROM bref/php-$PHP_VERSION AS rr-http

# Copy the console layer into /opt
COPY --link --from=rr-http-zip /opt /opt
COPY --link --from=rr-http-zip /var/runtime/bootstrap /var/runtime/bootstrap
