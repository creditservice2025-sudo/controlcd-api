#!/bin/bash

# Check if a database name is provided
if [ -z "$1" ]; then
  echo "Usage: $0 <database_name>"
  exit 1
fi

DB_NAME=$1
SSH_KEY="/home/mario-d-az/.ssh/id_rsa_mario_controlcd"
REMOTE_HOST="root@146.190.147.164"

echo "Creating database '$DB_NAME' on $REMOTE_HOST..."

# Use SSH_AUTH_SOCK="" to bypass any potential agent issues
SSH_AUTH_SOCK="" ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o IdentitiesOnly=yes "$REMOTE_HOST" "mysql -e 'CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;'"

if [ $? -eq 0 ]; then
  echo "Database '$DB_NAME' created successfully (or already exists)."

  echo "Granting permissions to 'andres_controlcd'..."
  SSH_AUTH_SOCK="" ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o IdentitiesOnly=yes "$REMOTE_HOST" "mysql -e 'GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '\''andres_controlcd'\''@'\''%'\''; FLUSH PRIVILEGES;'"

  if [ $? -eq 0 ]; then
    echo "Permissions granted to 'andres_controlcd'."
  else
    echo "Failed to grant permissions."
  fi
else
  echo "Failed to create database."
  exit 1
fi
