#!/usr/bin/env bash
# Description: Lints the PHP 7.3-compatible code (src/, include/overview_list.php,
#              tools/, tests/) and runs the PHPUnit suite under PHP 7.3.4 in the
#              official php:7.3.4-cli container, the oldest PHP the new code supports.
# Version:     1.00.000
# Usage:       tools/check_php73.sh          (or: composer check-php73)
# Env vars:    CONTAINER_ENGINE  (optional) podman or docker; default: podman, else docker
# Depends:     podman or docker; vendor/ installed with dev tools (composer install-dev)
# Concurrent:  Yes — the container mounts the project read-only.
set -Eeuo pipefail

trap 'log_error "Failed on line ${LINENO}"' ERR

readonly IMAGE="docker.io/library/php:7.3.4-cli"
readonly PROJECT_DIR="$(cd "$(dirname "${0}")/.." && pwd)"

log() {
	local level msg
	# Default to INFO when called with a single argument
	if [ "${#}" -eq 1 ]
	then
		level="INFO"
		msg="${1}"
	else
		level="${1}"
		msg="${2}"
	fi

	if [ -z "${RUN_ID:-}" ]
	then
		RUN_ID="$(head -c4 /dev/urandom | xxd -p)"
		export RUN_ID
	fi

	echo "$(date +%Y-%m-%dT%H:%M:%S.%N%z) - ${level} - ${RUN_ID} - ${msg}" >&2
}

log_info()  { log "INFO"  "${1}"; }
log_error() { log "ERROR" "${1}"; }

# Picks the container engine: CONTAINER_ENGINE if set, otherwise podman, otherwise docker.
#
# @output  stdout  Name of the engine binary.
# @return  0  An engine was found.
# @return  127  Neither podman nor docker is installed.
find_engine() {
	local engine

	for engine in "${CONTAINER_ENGINE:-}" podman docker
	do
		[ -n "${engine}" ] && command -v "${engine}" > /dev/null && {
			echo "${engine}"
			return 0
		}
	done

	return 127
}

main() {
	local engine

	engine="$(find_engine)" || {
		log_error "Neither podman nor docker found; install one or set CONTAINER_ENGINE"
		exit 127
	}

	[ -x "${PROJECT_DIR}/vendor/bin/phpunit" ] || {
		log_error "vendor/bin/phpunit missing; run: composer install-dev"
		exit 1
	}

	log_info "Linting and testing under PHP 7.3.4 with ${engine}"
	# :ro keeps the container from changing the project; the result cache is turned off for that reason
	"${engine}" run --rm \
		-v "${PROJECT_DIR}:/app:ro,Z" \
		-w /app \
		"${IMAGE}" \
		sh -c 'find src include/overview_list.php tools tests -name "*.php" -print0 \
			| xargs -0 -n 1 php -l > /dev/null \
			&& php vendor/bin/phpunit --do-not-cache-result'

	log_info "PHP 7.3.4: lint and tests passed"
}

main "${@}"
