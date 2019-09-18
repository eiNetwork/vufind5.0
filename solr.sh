#!/bin/sh
#
# Startup script for the VuFind Jetty Server under *nix systems
#
# Configuration variables
#
# VUFIND_HOME
#   Home of the VuFind installation.
#
# SOLR_BIN
#   Home of the Solr executable scripts.
#
# SOLR_HEAP
#   Size of the Solr heap (i.e. 512M, 2G, etc.). Defaults to 1G.
#
# SOLR_HOME
#   Home of the Solr indexes and configurations.
#
# SOLR_PORT
#   Network port for Solr. Defaults to 8080.
#
# JAVA_HOME
#   Home of Java installation (not directly used by this script, but passed along to
#   the standard Solr control script).
#
# SOLR_ADDITIONAL_START_OPTIONS
#   Additional options to pass to the solr binary at startup.
#
# SOLR_ADDITIONAL_JVM_OPTIONS
#   Additional options to pass to the JVM when launching Solr.
#

usage()
{
    echo "Usage: $0 {start|stop|restart|status}"
    exit 1
}


[ $# -gt 0 ] || usage

# Set VUFIND50_HOME
if [ -z "$VUFIND50_HOME" ]
then
  # set VUFIND_HOME to the absolute path of the directory containing this script
  # https://stackoverflow.com/questions/4774054/reliable-way-for-a-bash-script-to-get-the-full-path-to-itself
  VUFIND50_HOME="$(cd "$(dirname "$0")" && pwd -P)"
  if [ -z "$VUFIND50_HOME" ]
  then
    exit 1
  fi
fi


if [ -z "$SOLR_HOME" ]
then
  SOLR_HOME="$VUFIND50_HOME/solr/vufind"
fi

if [ -z "$SOLR_LOGS_DIR" ]
then
  SOLR_LOGS_DIR="$SOLR_HOME/logs/jetty"
fi

if [ -z "$SOLR_BIN" ]
then
  SOLR_BIN="$VUFIND50_HOME/solr/vendor/bin"
fi

if [ -z "$SOLR_HEAP" ]
then
  SOLR_HEAP="1G"
fi

if [ -z "$SOLR_PORT" ]
then
  SOLR_PORT="8083"
fi

if [ -z "$SOLR_ADDITIONAL_START_OPTIONS" ]
then
  SOLR_ADDITIONAL_START_OPTIONS="-force"
fi

if [ -z "$SOLR_ADDITIONAL_JVM_OPTIONS" ]
then
  SOLR_ADDITIONAL_JVM_OPTIONS=""
fi

JAVA_HOME="/usr/lib/jvm/java-8-oracle"

export SOLR_LOGS_DIR=$SOLR_LOGS_DIR
"$SOLR_BIN/solr" "$1" ${SOLR_ADDITIONAL_START_OPTIONS} -p "$SOLR_PORT" -s "$SOLR_HOME" -m "$SOLR_HEAP" -a "-Ddisable.configEdit=true -Dsolr.log=$SOLR_LOGS_DIR $SOLR_ADDITIONAL_JVM_OPTIONS"
