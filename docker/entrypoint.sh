#!/bin/bash
set -e

# Cron jobs run in their own minimal environment and don't automatically see
# the variables docker-compose set on this container's main process — a
# well-known Docker gotcha. Dumping them to a file that the crontab entries
# source before running php fixes this; without it, DB_HOST etc. would be
# empty inside every scheduled sync job even though the app itself works fine.
printenv | grep -v "no_proxy" >> /etc/environment

service cron start

# PID 1 stays Apache in the foreground — this is what keeps the container
# running and lets `docker logs` show Apache's actual output.
exec apache2-foreground
