#!/bin/bash

# Based on the script by Yoast: https://github.com/Yoast/wordpress-seo/blob/trunk/bin/install-wp-tests.sh

set -e

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
WP_CORE_DIR=/tmp/wordpress/
WP_TESTS_DIR=/tmp/wordpress-tests-lib/

# Make sure we have the latest version of the script
if [ -f /tmp/install-wp-tests.sh ]; then
    rm /tmp/install-wp-tests.sh
fi

wget -nv -O /tmp/install-wp-tests.sh https://raw.githubusercontent.com/wp-cli/scaffold-command/master/templates/install-wp-tests.sh

bash /tmp/install-wp-tests.sh "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${WP_VERSION}" 
