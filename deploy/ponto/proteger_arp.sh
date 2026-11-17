#!/usr/bin/env bash
# =============================================================================
# Nexa Ponto - Trava os registros de ponto contra alteração e exclusão no banco.
#
# Uso (na VPS, como root, DEPOIS de aplicar a migração "Ponto Eletrônico - V2"):
#   bash /var/www/nexa/deploy/ponto/proteger_arp.sh                       # produção
#   bash /var/www/nexa/deploy/ponto/proteger_arp.sh nexaprospero_homolog  # homologação
#
# O que faz: cria dois gatilhos (triggers) na tabela ponto_arp_marcacoes que recusam
# qualquer UPDATE ou DELETE, venha de onde vier (sistema, phpMyAdmin, script...).
# O sistema só insere registros; com isto, nem um erro de programa nem alguém com
# acesso ao banco consegue mudar um registro de ponto sem desligar a trava como root
# (e mesmo assim a conferência de integridade, com o código hash, acusa a mudança).
#
# Por que precisa de root: com o log binário do MySQL ligado, criar gatilhos exige
# privilégio administrativo, que o usuário do sistema (www-data) não tem e não deve ter.
# Pode ser executado de novo sem problema (recria os gatilhos).
# =============================================================================
set -euo pipefail

DB="${1:-nexaprospero}"
ok()   { echo -e "\e[32m[OK]\e[0m $*"; }
die()  { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Execute como root (sudo bash $0 $DB)."
[[ "$DB" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome de banco inválido: $DB"

EXISTE=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}' AND table_name='ponto_arp_marcacoes'")
[ "$EXISTE" = "1" ] || die "A tabela ponto_arp_marcacoes não existe em ${DB}. Aplique antes a atualização 'Ponto Eletrônico - V2 Marcação de ponto' no Atualizador do Banco."

mysql "$DB" <<'SQL'
DROP TRIGGER IF EXISTS ponto_arp_marcacoes_no_update;
DROP TRIGGER IF EXISTS ponto_arp_marcacoes_no_delete;
CREATE TRIGGER ponto_arp_marcacoes_no_update BEFORE UPDATE ON ponto_arp_marcacoes FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro de ponto (ARP) imutavel: alteracao nao permitida';
CREATE TRIGGER ponto_arp_marcacoes_no_delete BEFORE DELETE ON ponto_arp_marcacoes FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro de ponto (ARP) imutavel: exclusao nao permitida';
SQL

N=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='${DB}' AND event_object_table='ponto_arp_marcacoes'")
[ "$N" = "2" ] || die "Os gatilhos não foram criados (encontrados: $N)."
ok "Registros de ponto protegidos em ${DB}: alteração e exclusão bloqueadas no próprio banco."
