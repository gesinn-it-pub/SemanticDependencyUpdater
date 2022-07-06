ARG MW_VERSION=1.35
FROM gesinn/docker-mediawiki-sqlite:${MW_VERSION}

ARG SMW_VERSION=4.0.1
RUN COMPOSER=composer.local.json composer require --no-update mediawiki/semantic-media-wiki ${SMW_VERSION} && \
    sudo -u www-data composer update && \
    php maintenance/update.php --skip-external-dependencies --quick

ENV EXTENSION=SemanticDependencyUpdater
COPY composer*.json /var/www/html/extensions/$EXTENSION/

RUN cd extensions/$EXTENSION && \
    composer update

COPY . /var/www/html/extensions/$EXTENSION

RUN echo \
        "wfLoadExtension( 'SemanticMediaWiki' );\n" \
        "enableSemantics( 'localhost' );\n" \
        "wfLoadExtension( '$EXTENSION' );\n" \
    >> LocalSettings.php
