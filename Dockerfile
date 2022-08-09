FROM php:8.0-apache

ENV LASTFM_BASEURL=https://ws.audioscrobbler.com/2.0/?
ENV LASTFM_PAGELIMIT=40

WORKDIR /var/www/html
COPY . .
EXPOSE 80