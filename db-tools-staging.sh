#!/usr/bin/env bash
set -euo pipefail

# ================== CONFIG ==================
BASE_DIR="/home/mario-d-az/controlcd-sql"
BACKUP_DIR="$BASE_DIR/backups"

# Defaults de conexión (puedes cambiarlos aquí o pasar flags)
DB_HOST="${DB_HOST:-146.190.147.164}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-andres_controlcd}"
DB_NAME_DEFAULT="${DB_NAME_DEFAULT:-controlcd}"
# ============================================

mkdir -p "$BACKUP_DIR"

color() { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
info()  { color "36" "$1"; }
ok()    { color "32" "$1"; }
err()   { color "31" "$1" >&2; }

prompt_password() {
  if [[ -z "${DB_PASS:-}" ]]; then
    read -r -s -p "Password para usuario $DB_USER: " DB_PASS
    echo
  fi
}

choose_file() {
  local dir="$1"
  info "Listando archivos en: $dir"
  mapfile -t files < <(find "$dir" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) -printf "%f\n" | sort)
  if (( ${#files[@]} == 0 )); then
    err "No hay archivos .sql/.sql.gz en $dir"
    return 1
  fi

  # Si existe fzf, usarlo
  if command -v fzf >/dev/null 2>&1; then
    SELECTED_FILE="$(printf "%s\n" "${files[@]}" | fzf --prompt="Selecciona dump: ")"
  else
    echo "Selecciona un archivo:"
    local i=1
    for f in "${files[@]}"; do
      printf " [%d] %s\n" "$i" "$f"
      ((i++))
    done
    read -r -p "Número: " idx
    if ! [[ "$idx" =~ ^[0-9]+$ ]] || (( idx < 1 || idx > ${#files[@]} )); then
      err "Selección inválida"
      return 1
    fi
    SELECTED_FILE="${files[$((idx-1))]}"
  fi

  DUMP_PATH="$dir/$SELECTED_FILE"
  ok "Seleccionado: $DUMP_PATH"
}

restore_db() {
  local db="${1:-$DB_NAME_DEFAULT}"

  choose_file "$BASE_DIR" || return 1
  prompt_password

  info "Restaurando en BD: $db"
  # Desactivar FKs durante import
  if [[ "$DUMP_PATH" == *.gz ]]; then
    info "Importando .gz con zcat..."
    # shellcheck disable=SC2086
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    zcat "$DUMP_PATH" | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" --max-allowed-packet=512M --init-command="SET FOREIGN_KEY_CHECKS=0" "$db"
  else
    info "Importando .sql..."
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" --max-allowed-packet=512M --init-command="SET FOREIGN_KEY_CHECKS=0" "$db" < "$DUMP_PATH"
  fi
  ok "Restore completado en BD: $db"
}

next_increment_name() {
  local base_name="$1"   # p.ej: controlcd_2025-11-15
  local ext="$2"         # .sql.gz
  local dir="$3"

  local candidate="$dir/${base_name}${ext}"
  if [[ ! -e "$candidate" ]]; then
    echo "$candidate"
    return 0
  fi

  local n=2
  while : ; do
    candidate="$dir/${base_name}_v${n}${ext}"
    [[ ! -e "$candidate" ]] && { echo "$candidate"; return 0; }
    ((n++))
  done
}

backup_db() {
  local db="${1:-$DB_NAME_DEFAULT}"
  prompt_password

  local date_tag
  date_tag="$(date +%F)" # YYYY-MM-DD
  local base_name="${db}_${date_tag}"
  local dest
  dest="$(next_increment_name "$base_name" ".sql.gz" "$BACKUP_DIR")"

  info "Generando backup de BD: $db -> $dest"
  mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
    --single-transaction --quick --lock-tables=false \
    --default-character-set=utf8mb4 \
    "$db" | gzip -9 > "$dest"

  ok "Backup creado: $dest"
  ls -lh "$dest"
}

print_config() {
  cat <<EOF
Conexión actual:
  Host: $DB_HOST
  Port: $DB_PORT
  User: $DB_USER
  BD por defecto: $DB_NAME_DEFAULT
Carpetas:
  BASE_DIR:   $BASE_DIR
  BACKUP_DIR: $BACKUP_DIR
Puedes sobreescribir con variables de entorno: DB_HOST, DB_PORT, DB_USER, DB_NAME_DEFAULT, DB_PASS
EOF
}

menu() {
  print_config
  echo
  echo "===== Menu ====="
  echo " 1) Restaurar BD (elige dump en $BASE_DIR)"
  echo " 2) Backup BD (guarda en $BACKUP_DIR con fecha e incremental)"
  echo " 3) Cambiar BD por defecto"
  echo " 4) Salir"
  read -r -p "Opción: " opt
  case "$opt" in
    1)
      read -r -p "Nombre de la BD destino [ENTER = $DB_NAME_DEFAULT]: " dbname
      dbname="${dbname:-$DB_NAME_DEFAULT}"
      restore_db "$dbname"
      ;;
    2)
      read -r -p "Nombre de la BD a respaldar [ENTER = $DB_NAME_DEFAULT]: " dbname
      dbname="${dbname:-$DB_NAME_DEFAULT}"
      backup_db "$dbname"
      ;;
    3)
      read -r -p "Nuevo nombre de BD por defecto: " newdb
      [[ -z "$newdb" ]] && { err "Nombre vacío"; exit 1; }
      DB_NAME_DEFAULT="$newdb"
      ok "BD por defecto actualizada a: $DB_NAME_DEFAULT"
      ;;
    4) exit 0 ;;
    *) err "Opción inválida" ;;
  esac
}

# Si se pasan flags, permitir modo no-interactivo:
# Ejemplos:
#   ./db-tools.sh --restore --db controlcd --file /path/file.sql.gz
#   ./db-tools.sh --backup --db controlcd
if (( $# > 0 )); then
  ACTION=""
  FILE=""
  DB="$DB_NAME_DEFAULT"
  while (( $# > 0 )); do
    case "$1" in
      --restore) ACTION="restore" ;;
      --backup)  ACTION="backup" ;;
      --db)      DB="${2:-$DB}"; shift ;;
      --file)    FILE="${2:-}"; shift ;;
      --host)    DB_HOST="${2:-$DB_HOST}"; shift ;;
      --port)    DB_PORT="${2:-$DB_PORT}"; shift ;;
      --user)    DB_USER="${2:-$DB_USER}"; shift ;;
      --help|-h) echo "Uso: $0 [--restore|--backup] [--db NOMBRE] [--file RUTA] [--host H] [--port P] [--user U]"; exit 0 ;;
      *) err "Flag desconocida: $1"; exit 1 ;;
    esac
    shift
  done

  if [[ "$ACTION" == "restore" ]]; then
    if [[ -n "$FILE" ]]; then
      DUMP_PATH="$FILE"
      restore_db "$DB"
    else
      restore_db "$DB"
    fi
    exit 0
  elif [[ "$ACTION" == "backup" ]]; then
    backup_db "$DB"
    exit 0
  else
    err "Debes indicar --restore o --backup"
    exit 1
  fi
fi

# Modo interactivo
while true; do
  menu
  echo
done
