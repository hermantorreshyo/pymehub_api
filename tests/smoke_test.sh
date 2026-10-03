#!/usr/bin/env bash
# Prueba de humo de Pyme Hub API (fases 0-2).
# Uso: tests/smoke_test.sh <URL_API> <ORIGEN_FRONTEND> <EMAIL_ADMIN> <PASSWORD_ADMIN>
# Crea dos empresas de prueba con NIF y emails aleatorios. No usar en producción con datos reales.
set -uo pipefail
B="${1:?URL de la API}"; ORIGIN="${2:?Origen del frontend}"; AE="${3:?email admin}"; AP="${4:?password admin}"
J='Content-Type: application/json'; T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
R=$RANDOM$RANDOM; PASS=0; FAIL=0
jq_(){ python3 -c "import sys,json
try: d=json.load(sys.stdin)
except Exception: d={}
try: print(eval(sys.argv[1]))
except Exception: print('')" "$1"; }
check(){ if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  OK   $1"; else FAIL=$((FAIL+1)); echo "  FALLO $1 (esperado: $3 · obtenido: $2)"; fi; }
code(){ jq_ "d.get('error',{}).get('code','')"; }

echo "== Salud"
check "health ok" "$(curl -s "$B/v1/health" | jq_ "d['data']['estado']")" "ok"

echo "== Administrador"
curl -s -c "$T/a" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"$AE\",\"password\":\"$AP\"}" > "$T/a.json"
check "login admin" "$(jq_ "d['data']['usuario']['perfil']" < "$T/a.json")" "ADMIN"
CA=$(jq_ "d['data']['csrf_token']" < "$T/a.json")
check "sin CSRF -> PH-AUTH-003" "$(curl -s -b "$T/a" -H "$J" "$B/v1/admin/tenants" -d '{}' | code)" "PH-AUTH-003"
check "validación -> PH-VAL-001" "$(curl -s -b "$T/a" -H "$J" -H "X-CSRF-Token: $CA" "$B/v1/admin/tenants" -d '{"nombre":"X","nif":"mal","plan":"PRO","ciclo_cobro":"MENSUAL","extra":1,"gerente":{"nombre":"Ana","email":"a@b.es"}}' | code)" "PH-VAL-001"

NIF1="B$(printf '%08d' $((R % 100000000)))"; NIF2="B$(printf '%08d' $(((R+7) % 100000000)))"
curl -s -b "$T/a" -H "$J" -H "X-CSRF-Token: $CA" "$B/v1/admin/tenants" -d "{\"nombre\":\"Prueba Completo $R\",\"nif\":\"$NIF1\",\"plan\":\"COMPLETO\",\"ciclo_cobro\":\"MENSUAL\",\"fecha_contrato_encargado\":\"2026-10-01\",\"gerente\":{\"nombre\":\"Ana\",\"apellidos\":\"Prueba\",\"email\":\"gerente$R@prueba.test\"}}" > "$T/e1.json"
check "alta empresa Completo" "$(jq_ "d['data']['suscripcion']['plan']" < "$T/e1.json")" "COMPLETO"
TOKG=$(jq_ "d['data']['invitacion_gerente']['enlace'].split('/')[-1]" < "$T/e1.json")

echo "== Invitaciones"
check "aceptar invitación" "$(curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKG\",\"password\":\"Gerente#Seguro1\",\"acepta_privacidad\":true}" | jq_ "'ok' if 'data' in d else ''")" "ok"
check "reutilizar invitación -> PH-AUTH-005" "$(curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKG\",\"password\":\"Gerente#Seguro1\",\"acepta_privacidad\":true}" | code)" "PH-AUTH-005"

echo "== Gerente"
curl -s -c "$T/g" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"gerente$R@prueba.test\",\"password\":\"Gerente#Seguro1\"}" > "$T/g.json"
check "login gerente" "$(jq_ "d['data']['usuario']['perfil']" < "$T/g.json")" "GERENTE"
check "features Completo incluye riesgo" "$(jq_ "'riesgo_rotacion' in d['data']['features']" < "$T/g.json")" "True"
CG=$(jq_ "d['data']['csrf_token']" < "$T/g.json")
check "gerente en zona admin -> PH-PERM-001" "$(curl -s -b "$T/g" "$B/v1/admin/tenants" | code)" "PH-PERM-001"
curl -s -b "$T/g" -H "$J" -H "X-CSRF-Token: $CG" "$B/v1/users" -d "{\"nombre\":\"Lucía\",\"apellidos\":\"Prueba\",\"email\":\"rrhh$R@prueba.test\"}" > "$T/h.json"
check "gerente crea RRHH" "$(jq_ "d['data']['perfil']" < "$T/h.json")" "RRHH"
RID=$(jq_ "d['data']['id']" < "$T/h.json"); TOKH=$(jq_ "d['data']['invitacion']['enlace'].split('/')[-1]" < "$T/h.json")
curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKH\",\"password\":\"Rrhh#Seguro2026\",\"acepta_privacidad\":true}" > /dev/null

echo "== RR. HH."
curl -s -c "$T/h" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"rrhh$R@prueba.test\",\"password\":\"Rrhh#Seguro2026\"}" > "$T/hl.json"
CH=$(jq_ "d['data']['csrf_token']" < "$T/hl.json")
check "RRHH no crea cuentas -> PH-PERM-001" "$(curl -s -b "$T/h" -H "$J" -H "X-CSRF-Token: $CH" "$B/v1/users" -d '{"nombre":"Xx","email":"x@x.es"}' | code)" "PH-PERM-001"

echo "== Aislamiento entre empresas"
curl -s -b "$T/a" -H "$J" -H "X-CSRF-Token: $CA" "$B/v1/admin/tenants" -d "{\"nombre\":\"Prueba Entrada $R\",\"nif\":\"$NIF2\",\"plan\":\"ENTRADA\",\"ciclo_cobro\":\"ANUAL\",\"gerente\":{\"nombre\":\"Pedro\",\"email\":\"gerente2$R@prueba.test\"}}" > "$T/e2.json"
TOKT=$(jq_ "d['data']['invitacion_gerente']['enlace'].split('/')[-1]" < "$T/e2.json")
curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKT\",\"password\":\"Gerente2#Seguro\",\"acepta_privacidad\":true}" > /dev/null
curl -s -c "$T/t" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"gerente2$R@prueba.test\",\"password\":\"Gerente2#Seguro\"}" > "$T/tl.json"
CT=$(jq_ "d['data']['csrf_token']" < "$T/tl.json")
check "plan Entrada sin riesgo" "$(jq_ "'riesgo_rotacion' in d['data']['features']" < "$T/tl.json")" "False"
check "otra empresa no ve el usuario -> PH-TENANT-001" "$(curl -s -b "$T/t" -X PATCH -H "$J" -H "X-CSRF-Token: $CT" "$B/v1/users/$RID" -d '{"estado_acceso":"BLOQUEADO"}' | code)" "PH-TENANT-001"

echo "== CORS"
check "preflight origen permitido" "$(curl -s -i -X OPTIONS -H "Origin: $ORIGIN" -H 'Access-Control-Request-Method: POST' "$B/v1/auth/login" | grep -ic '^access-control-allow-origin')" "1"
check "preflight origen ajeno" "$(curl -s -i -X OPTIONS -H 'Origin: https://ajeno.example' "$B/v1/auth/login" | grep -ic '^access-control-allow-origin')" "0"

echo "== Sesión y fuerza bruta"
check "logout" "$(curl -s -b "$T/g" -c "$T/g" -X POST -H "X-CSRF-Token: $CG" -o /dev/null -w '%{http_code}' "$B/v1/auth/logout")" "204"
check "tras logout -> PH-AUTH-002" "$(curl -s -b "$T/g" "$B/v1/auth/me" | code)" "PH-AUTH-002"
for i in 1 2 3 4 5; do curl -s -H "$J" "$B/v1/auth/login" -d "{\"email\":\"rrhh$R@prueba.test\",\"password\":\"mala\"}" > /dev/null; done
check "6.º intento fallido -> PH-AUTH-004" "$(curl -s -H "$J" "$B/v1/auth/login" -d "{\"email\":\"rrhh$R@prueba.test\",\"password\":\"Rrhh#Seguro2026\"}" | code)" "PH-AUTH-004"
check "ruta inexistente -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/v1/no-existe")" "404"

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"; [ "$FAIL" -eq 0 ]
