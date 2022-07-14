ARG MW_VERSION=1.35
FROM gesinn/docker-mediawiki-sqlite:${MW_VERSION}

# add /build-tools and /tools
RUN curl -LJ https://github.com/gesinn-it-pub/docker-mediawiki-tools/tarball/1.1.0 \
    | tar xzC / --strip-components 1 && chmod +x /build-tools/* /tools/*
ENV PATH="/tools:/build-tools:${PATH}"

RUN sed -i s/80/8080/g /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

RUN apt-get update && apt-get install -y memcached && rm -rf /var/lib/apt/lists/*

ARG SMW_VERSION=4.0.1
RUN COMPOSER=composer.local.json composer require --no-update mediawiki/semantic-media-wiki ${SMW_VERSION} && \
    sudo -u www-data composer update && \
    rm LocalSettings.php && \
    echo '<?php\n'\
        'wfLoadExtension( "SemanticMediaWiki" );\n'\
        'enableSemantics( $wgServer );\n'\
        '$smwgEnabledQueryDependencyLinksStore = true;\n'\
        '$wgMainCacheType = CACHE_MEMCACHED;\n'\
        '$wgParserCacheType = CACHE_MEMCACHED;\n'\
        '$wgMessageCacheType = CACHE_MEMCACHED;\n'\
        '$wgMemCachedServers = [ "127.0.0.1:11211" ];\n'\
        >> LocalSettings.Include.php



ENV EXTENSION=SemanticDependencyUpdater
COPY composer*.json /var/www/html/extensions/$EXTENSION/
COPY tests/http-integration/package*.json /var/www/html/extensions/$EXTENSION/tests/http-integration/

RUN cd extensions/$EXTENSION && \
    composer update && \
    cd tests/http-integration && \
    npm ci

COPY . /var/www/html/extensions/$EXTENSION

# RUN echo \
#         "wfLoadExtension( '$EXTENSION' );\n" \
#     >> LocalSettings.Include.php
