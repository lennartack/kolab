#!/bin/bash

set -eu

# Process the Redis configuration files
echo 'Processing Redis configuration files ...'
if [[ -v REDIS_PASSWORD ]]; then
  echo "requirepass \"$REDIS_PASSWORD\"" >> /etc/redis/redis.conf
else
  echo 'WARNING: setting REDIS_PASSWORD is recommended'
fi

if [[ -v MEMORY_LIMIT ]]; then
    # Add a 20% margin between the configured limit, and the kubernetes maximum memory limit
    MAX_MEMORY="$(env | grep MEMORY | sed 's/MEMORY_LIMIT=//' | awk '{$0=int($1 * 0.8);print $0}')"
    echo "maxmemory $MAX_MEMORY" >> /etc/redis/redis.conf
    echo "maxmemory-policy volatile-lru" >> /etc/redis/redis.conf
else
  echo 'WARNING: setting MEMORY_LIMIT is recommended'
fi

# Restart the Redis server with public IP bindings
echo 'Running final exec -- Only Redis logs after this point'
exec /usr/bin/redis-server /etc/redis/redis.conf --daemonize no "$@" 2>&1
