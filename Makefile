-include .env
export

# setup for docker-compose-ci build directory
# delete "build" directory to update docker-compose-ci

ifeq (,$(wildcard ./build/))
    $(shell git submodule update --init --remote)
endif

EXTENSION=SemanticDependencyUpdater

# docker images
MW_VERSION?=1.43
PHP_VERSION?=8.2
DB_TYPE?=mysql
DB_IMAGE?="mariadb:11.2"

# extensions
SMW_VERSION?=7.2.0

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
