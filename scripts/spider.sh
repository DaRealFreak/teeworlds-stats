#!/bin/bash

# ToDo:
# - .env variables for this script
# - get gatling.io to work with these generated urls

CRAWLER_HOME="http://tw-stats.local/"
CRAWLER_DOMAINS="tw-stats.local"
CRAWLER_DEPTH=5
OUTPUT="./urls.txt"

ENVPATH=./../.env
# Retrieve the absolute path this script is in
SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")

loadDotEnv () {
	# credit for this part of the script: https://github.com/builtinnya/dotenv-shell-loader
	cd "${SCRIPTPATH}" || exit
	DOTENV_SHELL_LOADER_SAVED_OPTS=$(set +o)
	set -o allexport
	# shellcheck disable=SC1091
	# shellcheck source=/dev/null.
	[ -f ${ENVPATH} ] && . ${ENVPATH}
	set +o allexport
	eval "${DOTENV_SHELL_LOADER_SAVED_OPTS}"
	unset DOTENV_SHELL_LOADER_SAVED_OPTS
}

loadDotEnv

wget -r --spider --delete-after --force-html -D "$CRAWLER_DOMAINS" -l ${CRAWLER_DEPTH} "$CRAWLER_HOME" 2>&1 \
    | grep '^--' | awk '{ print $3 }' | grep -v '\.\(css\|js\|png\|gif\|jpg\)$' | sort | uniq > ${OUTPUT}