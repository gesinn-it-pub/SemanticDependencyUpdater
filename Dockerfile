ARG MW_VERSION
ARG PHP_VERSION
ARG DB_TYPE
ARG SMW_VERSION=4.0.1

FROM gesinn/mediawiki-ci:${MW_VERSION}-php${PHP_VERSION}
ENV EXTENSION=SemanticDependencyUpdater


RUN COMPOSER=composer.local.json composer require --no-update mediawiki/semantic-media-wiki ${SMW_VERSION}
RUN composer update

COPY composer*.json /var/www/html/extensions/$EXTENSION/

RUN cd extensions/$EXTENSION && \
    composer update

COPY . /var/www/html/extensions/$EXTENSION

RUN echo \
        "wfLoadExtension( 'SemanticMediaWiki' );\n" \
        "enableSemantics( 'localhost' );\n" \
        "wfLoadExtension( '$EXTENSION' );\n" \
    >> __setup_extension__
