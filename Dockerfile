ARG MW_VERSION
ARG PHP_VERSION
ARG DB_TYPE

FROM gesinn/mediawiki-ci:${MW_VERSION}-php${PHP_VERSION}
ENV EXTENSION=SemanticDependencyUpdater
ARG SMW_VERSION


RUN composer-require.sh mediawiki/semantic-media-wiki ${SMW_VERSION}
RUN composer update

RUN chown -R www-data:www-data /var/www/html/extensions/SemanticMediaWiki/

COPY composer*.json /var/www/html/extensions/$EXTENSION/

RUN cd extensions/$EXTENSION && \
    composer update

COPY . /var/www/html/extensions/$EXTENSION

RUN echo \
        "wfLoadExtension( 'SemanticMediaWiki' );\n" \
        "enableSemantics( 'localhost' );\n" \
        "wfLoadExtension( '$EXTENSION' );\n" \
    >> __setup_extension__
