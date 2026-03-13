#!/bin/bash

DB_NAME="control-cd-20260311"
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
REMOTE_HOST="root@146.190.147.164"
USER="staging-controlcd"

echo "Granting permissions to '$USER' on '$DB_NAME'..."

SSH_AUTH_SOCK="" ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o IdentitiesOnly=yes "$REMOTE_HOST" "mysql -e 'GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '\''$USER'\''@'\''127.0.0.1'\''; FLUSH PRIVILEGES;'"

if [ $? -eq 0 ]; then
  echo "Permissions granted successfully to '$USER'@'127.0.0.1'."
else
  echo "Failed to grant permissions."
  exit 1
fi
