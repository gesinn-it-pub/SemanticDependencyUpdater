#! /bin/bash
set -ex

BASE_PATH=$(pwd)
MW_INSTALL_PATH=$BASE_PATH/../mw

cd $MW_INSTALL_PATH/extensions/SemanticDependencyUpdater

if [ "$TYPE" == "coverage" ]
then
	php ../../tests/phpunit/phpunit.php -c phpunit.xml.dist --coverage-clover $BASE_PATH/build/coverage.clover
else
  php ../../tests/phpunit/phpunit.php -c phpunit.xml.dist
fi
