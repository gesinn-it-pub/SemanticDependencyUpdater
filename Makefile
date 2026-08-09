-include .env
export

# setup for docker-compose-ci build directory
# delete "build" directory to update docker-compose-ci

ifeq (,$(wildcard ./build/))
    $(shell git submodule update --init --remote)
endif

EXTENSION=SemanticDependencyUpdater

# docker images
MW_VERSION?=1.39
PHP_VERSION?=8.1
DB_TYPE?=mysql
DB_IMAGE?="mariadb:10"

# extensions
SMW_VERSION?=5.1.0

# SemanticExtraSpecialProperties version used for the ___REVID-based
# $wgSDUIgnoredProperties default (see extensions.local.json.template).
# SESP 5.x+ requires SemanticMediaWiki >= 7.0 and won't load against older
# SMW; SESP 4.0.0 is the last release before that requirement was added and
# targets pre-SMW-7 APIs, so it won't load against SMW 7.x either. There is
# no single SESP version compatible with both - pick per SMW_VERSION.
ifeq ($(shell echo $(SMW_VERSION) | cut -d. -f1),6)
    SESP_VERSION?=4.0.0
else
    SESP_VERSION?=5.0.1
endif

# composer
# Enables "composer update" inside of extension
COMPOSER_EXT?=true

# nodejs
# Enables node.js related tests and "npm install"
# NODE_JS?=true

# check for build dir and git submodule init if it does not exist
include build/Makefile

.PHONY: composer-phan
composer-phan: .init
	$(compose-exec-wiki) bash -c "cd $(EXTENSION_FOLDER) && composer phan $(COMPOSER_PARAMS)"

.PHONY: render-extensions-local-json
render-extensions-local-json:
	@if [ -f extensions.local.json.template ]; then \
		sed 's/$${SESP_VERSION}/$(SESP_VERSION)/g' \
			extensions.local.json.template > extensions.local.json; \
		echo "Rendered extensions.local.json (SESP_VERSION=$(SESP_VERSION))"; \
	fi

# Override build/Makefile's "up" and "install" so the templated
# extensions.local.json exists before the Dockerfile's
# "COPY extensions.local.json* /tmp/" step runs. Make uses the last-defined
# rule for a given target, so redefining them here (after the include above)
# takes precedence over build/Makefile's own definitions without needing to
# patch that submodule.
.PHONY: up
up: render-extensions-local-json .init .build .up

.PHONY: install
install: render-extensions-local-json destroy up .install
