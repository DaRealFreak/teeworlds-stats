#!/usr/bin/env bash
# Usage:
# docker exec -ti [DOCKER_NAME] [BASH_PATH] && sh /path/to/this/script
#
# pay attention for the line feed of the shell and .env file
# it has to be LF or else the mysqldump command will probably fail
#

# just some common settings
GIT_AUTHOR_NAME="Steffen Keuper"
GIT_AUTHOR_EMAIL="steffen.keuper@web.de"
DBFOLDER=/../db
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

dumpDatabase () {
	cd "${SCRIPTPATH}${DBFOLDER}" || exit
	if [ -z "$TWSTATS_DB_HOST" ] || [ -z "$TWSTATS_DB_USER" ] || [ -z "$TWSTATS_DB_PASS" ] || [ -z "$TWSTATS_DB" ]; then
		echo "one or more variables for the database connection are not defined in ${SCRIPTPATH}/${ENVPATH}"
		exit 1
	fi
	mysqldump -h"${TWSTATS_DB_HOST}" -u"${TWSTATS_DB_USER}" -p"${TWSTATS_DB_PASS}" -d "${TWSTATS_DB}" > schema.sql
	# mysqldump -h${TWSTATS_DB_HOST} -u${TWSTATS_DB_USER} -p${TWSTATS_DB_PASS} ${TWSTATS_DB} > schema.sql # the -d flag means "no data"
}

commitGit () {
	if [ -z "$TWSTATS_DB" ]; then
		echo "database name variable is not defined in ${SCRIPTPATH}/${ENVPATH}"
		exit 1
	fi
	git add schema.sql
	GIT_COMMITTER_NAME=${GIT_AUTHOR_NAME} GIT_COMMITTER_EMAIL=${GIT_AUTHOR_EMAIL} \
		git commit -m "[TASK] ${TWSTATS_DB} update schema version $(date)" --author="${GIT_AUTHOR_NAME} <${GIT_AUTHOR_EMAIL}"
}

loadDotEnv
dumpDatabase
commitGit
