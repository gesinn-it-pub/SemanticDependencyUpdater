#!/bin/bash
set -ex

BASE_PATH=$(pwd)
MW_INSTALL_PATH=$BASE_PATH/../mw

## Install
echo -e "Running SemanticDependencyUpdater install on $TRAVIS_BRANCH \n"

cd $MW_INSTALL_PATH/extensions

if [ "$SDU" != "" ]
then
  git clone https://github.com/gesinn-it/SemanticDependencyUpdater.git
  cd SemanticDependencyUpdater
  git checkout $SDU
else
  git clone https://github.com/gesinn-it/SemanticDependencyUpdater.git
  cd SemanticDependencyUpdater

  # Pull request number, "false" if it's not a pull request
  # After the install via composer an additional get fetch is carried out to
  # update th repository to make sure that the latests code changes are
  # deployed for testing
  if [ "$TRAVIS_PULL_REQUEST" != "false" ]
  then
    git fetch origin +refs/pull/"$TRAVIS_PULL_REQUEST"/merge:
    git checkout -qf FETCH_HEAD
  else
    git fetch origin "$TRAVIS_BRANCH"
    git checkout -qf FETCH_HEAD
  fi

  cd ../..
fi

composer dump-autoload


## Configure
cd $MW_INSTALL_PATH
echo 'wfLoadExtension( "SemanticDependencyUpdater" );' >> LocalSettings.php
