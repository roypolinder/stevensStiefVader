#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  exec sudo bash "$0" "$@"
fi

export DEBIAN_FRONTEND=noninteractive

echo "[1/6] apt update"
apt-get update

echo "[2/6] install system packages"
apt-get install -y \
  ca-certificates \
  curl \
  git \
  unzip \
  zip \
  ffmpeg \
  libopus0 \
  libopus-dev \
  libsodium23 \
  pkg-config

echo "[3/6] install PHP + common extensions"
apt-get install -y \
  php-cli \
  php-common \
  php-mbstring \
  php-xml \
  php-curl \
  php-zip \
  php-intl \
  php-bcmath \
  php-sqlite3 \
  php-readline \
  php-opcache

if apt-cache show php-sodium >/dev/null 2>&1; then
  apt-get install -y php-sodium
else
  echo "php-sodium package not found (expected on newer distro; sodium is usually built into PHP)."
fi

echo "[4/6] install FFI extension"
if apt-cache show php-ffi >/dev/null 2>&1; then
  apt-get install -y php-ffi
else
  PHP_MM="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  apt-get install -y "php${PHP_MM}-ffi"
fi

echo "[5/6] install Composer"
apt-get install -y composer

echo "[6/6] enable FFI for CLI"
PHP_INI_CLI="$(php --ini | awk -F': ' '/Loaded Configuration File/{print $2}')"
if [[ -n "${PHP_INI_CLI}" && -f "${PHP_INI_CLI}" ]]; then
  if ! grep -Eq '^\s*ffi\.enable\s*=\s*(On|on|true|1|preload)\s*$' "${PHP_INI_CLI}"; then
    if grep -Eq '^\s*ffi\.enable\s*=' "${PHP_INI_CLI}"; then
      sed -i 's/^\s*ffi\.enable\s*=.*/ffi.enable=true/' "${PHP_INI_CLI}"
    else
      printf '\nffi.enable=true\n' >> "${PHP_INI_CLI}"
    fi
  fi
fi

echo "Done. Quick checks:"
php -v || true
php -m | grep -Ei 'ffi|sodium' || true
php -i | grep -i 'ffi.enable' || true
composer --version || true
ffmpeg -version | head -n 1 || true

echo
echo "Next steps in project root:"
echo "  composer install"
echo "  php scripts/patch-voice.php"
echo "  cp .env.example .env   # if needed"
echo "  php laracord"
