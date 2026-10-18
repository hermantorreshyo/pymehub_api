#!/usr/bin/env bash
# Prueba de humo de Pyme Hub API (fases 0-7 y demo comercial).
# Uso: tests/smoke_test.sh <URL_API> <ORIGEN_FRONTEND> <EMAIL_ADMIN> <PASSWORD_ADMIN> [PASSWORD_DEMO] [URL_WEB]
#   Variables opcionales para el flujo de pago (fase 9c) contra el simulador tests/stripe_mock.php:
#     SMOKE_STRIPE_WHSEC=<stripe.webhook_secret de la API>  SMOKE_STRIPE_MOCK=http://127.0.0.1:12111
#   Sin ellas solo se comprueba la vigencia y que la pasarela responde de forma controlada.
#   PASSWORD_DEMO: contraseña de los usuarios demo (config demo.password). Sin ella se saltan las
#                  comprobaciones de inicio de sesión demo.
#   URL_WEB: dónde comprobar la redirección /demo (por defecto, ORIGEN_FRONTEND).
# Crea dos empresas de prueba con NIF y emails aleatorios. No usar en producción con datos reales.
set -uo pipefail
B="${1:?URL de la API}"; ORIGIN="${2:?Origen del frontend}"; AE="${3:?email admin}"; AP="${4:?password admin}"
DP="${5:-}"; WEB="${6:-$ORIGIN}"
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
if [ "$(jq_ "d['data']['usuario']['perfil']" < "$T/a.json")" != "ADMIN" ]; then
  echo
  echo "No se ha podido iniciar sesión como administrador ($(code < "$T/a.json")). Revisa el email y la contraseña:"
  echo "usa la del instalador, no el texto de ejemplo. Tras 5 intentos fallidos, espera 15 minutos."
  exit 1
fi
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

# --- Fase 3 · Organización ---------------------------------------------------
send(){ curl -s -b "$T/$1" -X "$2" -H "$J" -H "X-CSRF-Token: $3" "$B$4" -d "$5"; }
csv(){ curl -s -b "$T/$1" -H 'Content-Type: text/csv' -H "X-CSRF-Token: $2" "$B$3" --data-binary "@$4"; }
total(){ curl -s -b "$T/$1" "$B$2" | jq_ "d['meta']['total']"; }
campos(){ jq_ "' '.join(str(d['data'][k]) for k in '$1'.split())"; }
HOY=$(date -u +%F)

echo "== Equipos"
send g POST "$CG" /v1/teams '{"nombre":"Norte","zona":"Madrid norte"}' > "$T/eqn.json"
check "gerente crea equipo" "$(jq_ "d['data']['nombre']" < "$T/eqn.json")" "Norte"
EQN=$(jq_ "d['data']['id']" < "$T/eqn.json")
send g POST "$CG" /v1/teams '{"nombre":"Sur"}' > /dev/null
check "equipo duplicado -> PH-VAL-001" "$(send g POST "$CG" /v1/teams '{"nombre":"norte"}' | code)" "PH-VAL-001"
check "RRHH ve los equipos" "$(total h /v1/teams)" "2"
check "RRHH no crea equipos -> PH-PERM-001" "$(send h POST "$CH" /v1/teams '{"nombre":"Este"}' | code)" "PH-PERM-001"
check "RRHH no edita equipos -> PH-PERM-001" "$(send h PATCH "$CH" "/v1/teams/$EQN" '{"zona":"X"}' | code)" "PH-PERM-001"
EQX=$(send g POST "$CG" /v1/teams '{"nombre":"Temporal"}' | jq_ "d['data']['id']")
check "borrar equipo vacío" "$(curl -s -b "$T/g" -X DELETE -H "X-CSRF-Token: $CG" -o /dev/null -w '%{http_code}' "$B/v1/teams/$EQX")" "204"
check "recrear equipo con el nombre de uno borrado" "$(send g POST "$CG" /v1/teams '{"nombre":"Temporal"}' | jq_ "d['data']['nombre']")" "Temporal"

echo "== Empleados (RR. HH.)"
send h POST "$CH" /v1/employees "{\"nombre\":\"Carlos\",\"apellidos\":\"Prueba\",\"codigo_interno\":\"SMK-1\",\"puesto\":\"CONDUCTOR\",\"tipo_contrato\":\"INDEFINIDO\",\"turno\":\"MANANA\",\"fecha_alta\":\"2024-01-15\",\"equipo_id\":$EQN}" > "$T/emp.json"
check "alta sin email -> SIN_ACCESO" "$(jq_ "d['data']['estado_acceso']" < "$T/emp.json")" "SIN_ACCESO"
EID=$(jq_ "d['data']['id']" < "$T/emp.json")
check "código interno duplicado -> PH-VAL-001" "$(send h POST "$CH" /v1/employees '{"nombre":"Otro","codigo_interno":"SMK-1","puesto":"OTRO","tipo_contrato":"ETT","turno":"TARDE","fecha_alta":"2024-01-15"}' | code)" "PH-VAL-001"
check "interlocutor_id en el cuerpo -> PH-VAL-001" "$(send h POST "$CH" /v1/employees '{"nombre":"Otro","puesto":"OTRO","tipo_contrato":"ETT","turno":"TARDE","fecha_alta":"2024-01-15","interlocutor_id":1}' | code)" "PH-VAL-001"
check "editar turno" "$(send h PATCH "$CH" "/v1/employees/$EID" '{"turno":"TARDE"}' | jq_ "d['data']['turno']")" "TARDE"
check "invitar sin email -> PH-VAL-001" "$(send h POST "$CH" "/v1/employees/$EID/invitation" '' | code)" "PH-VAL-001"
check "invitar con email" "$(send h POST "$CH" "/v1/employees/$EID/invitation" "{\"email\":\"emp$R@prueba.test\"}" | jq_ "'ok' if d['data']['enlace'] else ''")" "ok"
check "empleado invitado" "$(curl -s -b "$T/h" "$B/v1/employees/$EID" | jq_ "d['data']['estado_acceso']")" "INVITADO"
check "borrar equipo con empleados -> PH-VAL-001" "$(curl -s -b "$T/g" -X DELETE -H "X-CSRF-Token: $CG" "$B/v1/teams/$EQN" | code)" "PH-VAL-001"

echo "== Incidencias"
check "registrar retraso" "$(send h POST "$CH" "/v1/employees/$EID/incidents" "{\"tipo\":\"RETRASO\",\"fecha\":\"$HOY\"}" | jq_ "d['data']['tipo']")" "RETRASO"
check "baja médica no admitida (LEG-012) -> PH-VAL-001" "$(send h POST "$CH" "/v1/employees/$EID/incidents" "{\"tipo\":\"BAJA_MEDICA\",\"fecha\":\"$HOY\"}" | code)" "PH-VAL-001"
check "listar incidencias" "$(total h "/v1/employees/$EID/incidents")" "1"

echo "== Importación CSV (RF-020)"
printf '\xEF\xBB\xBFcodigo_interno;nombre;apellidos;email;puesto;tipo_contrato;turno;fecha_alta;equipo\r\nSMK-2;Laura;Prueba;;Repartidor;Temporal;Mañana;2025-02-01;Norte\r\nSMK-3;Iván;Prueba;;MOZO_ALMACEN;ETT;NOCHE;15/03/2025;sur\r\nSMK-1;Carlos;Prueba;;REPARTIDOR;INDEFINIDO;TARDE;15/01/2024;Norte\r\n' > "$T/ok.csv"
printf 'codigo_interno,nombre,puesto,tipo_contrato,turno,fecha_alta,equipo\nSMK-4,Rosa,CONDUCTOR,INDEFINIDO,Madrugada,2025-01-01,Norte\nSMK-5,Hugo,CONDUCTOR,INDEFINIDO,TARDE,2025-01-01,Inexistente\nSMK-4,Rosa,CONDUCTOR,INDEFINIDO,TARDE,2025-01-01,Norte\n' > "$T/mal.csv"
check "simulación válida (RRHH): altas y cambios" "$(csv h "$CH" '/v1/employees/import?simular=1' "$T/ok.csv" | campos 'valido altas cambios')" "True 2 1"
check "la simulación no escribe" "$(total h /v1/employees)" "1"
check "importación real" "$(csv h "$CH" /v1/employees/import "$T/ok.csv" | campos 'simulado altas cambios')" "False 2 1"
check "empleados tras importar" "$(total h /v1/employees)" "3"
check "el cambio se aplica a la ficha" "$(curl -s -b "$T/h" "$B/v1/employees/$EID" | jq_ "d['data']['puesto']")" "REPARTIDOR"
check "reimportar sin cambios" "$(csv h "$CH" /v1/employees/import "$T/ok.csv" | campos 'altas cambios sin_cambios')" "0 0 3"
csv h "$CH" /v1/employees/import "$T/mal.csv" > "$T/mal.json"
check "CSV con errores -> PH-VAL-002" "$(code < "$T/mal.json")" "PH-VAL-002"
check "errores con fila y columna" "$(jq_ "' '.join(str(e['fila'])+':'+str(e['columna']) for e in d['error']['details']['errores'])" < "$T/mal.json")" "2:turno 3:equipo 4:codigo_interno"
check "todo o nada: no importa ninguna" "$(total h /v1/employees)" "3"
check "simulación con errores -> valido False" "$(csv h "$CH" '/v1/employees/import?simular=1' "$T/mal.csv" | jq_ "str(d['data']['valido'])+' '+str(len(d['data']['errores']))")" "False 3"
check "CSV sin Content-Type text/csv -> PH-VAL-001" "$(send h POST "$CH" /v1/employees/import 'a;b' | code)" "PH-VAL-001"

echo "== Aislamiento entre empresas (fase 3)"
for M in GET PATCH DELETE; do
  check "$M empleado ajeno -> PH-TENANT-001" "$(send t $M "$CT" "/v1/employees/$EID" '{"turno":"NOCHE"}' | code)" "PH-TENANT-001"
done
check "invitar empleado ajeno -> PH-TENANT-001" "$(send t POST "$CT" "/v1/employees/$EID/invitation" '{"email":"x@x.es"}' | code)" "PH-TENANT-001"
check "ver incidencias ajenas -> PH-TENANT-001" "$(curl -s -b "$T/t" "$B/v1/employees/$EID/incidents" | code)" "PH-TENANT-001"
check "crear incidencia ajena -> PH-TENANT-001" "$(send t POST "$CT" "/v1/employees/$EID/incidents" "{\"tipo\":\"RETRASO\",\"fecha\":\"$HOY\"}" | code)" "PH-TENANT-001"
check "editar equipo ajeno -> PH-TENANT-001" "$(send t PATCH "$CT" "/v1/teams/$EQN" '{"zona":"X"}' | code)" "PH-TENANT-001"
check "borrar equipo ajeno -> PH-TENANT-001" "$(send t DELETE "$CT" "/v1/teams/$EQN" '' | code)" "PH-TENANT-001"
check "no lista equipos ajenos" "$(total t /v1/teams)" "0"
check "no lista empleados ajenos" "$(total t /v1/employees)" "0"
check "equipo ajeno al dar de alta -> PH-VAL-001" "$(send t POST "$CT" /v1/employees "{\"nombre\":\"Eva\",\"puesto\":\"OTRO\",\"tipo_contrato\":\"ETT\",\"turno\":\"TARDE\",\"fecha_alta\":\"2024-01-15\",\"equipo_id\":$EQN}" | code)" "PH-VAL-001"
check "CSV con equipos ajenos -> PH-VAL-002" "$(csv t "$CT" /v1/employees/import "$T/ok.csv" | code)" "PH-VAL-002"
printf 'codigo_interno;nombre;puesto;tipo_contrato;turno;fecha_alta\nSMK-1;Eva;OTRO;ETT;TARDE;2024-01-15\n' > "$T/e2.csv"
check "mismo código en otra empresa = alta, no cambio" "$(csv t "$CT" '/v1/employees/import?simular=1' "$T/e2.csv" | campos 'altas cambios')" "1 0"

echo "== Contrato de encargado y límite del plan (empresa 2)"
E2=$(send t POST "$CT" /v1/employees "{\"nombre\":\"Eva\",\"email\":\"eva$R@prueba.test\",\"puesto\":\"OTRO\",\"tipo_contrato\":\"ETT\",\"turno\":\"TARDE\",\"fecha_alta\":\"2024-01-15\"}" | jq_ "d['data']['id']")
check "invitar sin contrato de encargado (LEG-016) -> PH-TENANT-003" "$(send t POST "$CT" "/v1/employees/$E2/invitation" '' | code)" "PH-TENANT-003"
EMP2=$(jq_ "d['data']['id']" < "$T/e2.json")
send a PATCH "$CA" "/v1/admin/tenants/$EMP2" '{"limite_empleados":1}' > /dev/null
check "alta por encima del límite -> PH-TENANT-002" "$(send t POST "$CT" /v1/employees '{"nombre":"Leo","puesto":"OTRO","tipo_contrato":"ETT","turno":"TARDE","fecha_alta":"2024-01-15"}' | code)" "PH-TENANT-002"
check "CSV por encima del límite -> PH-TENANT-002" "$(csv t "$CT" /v1/employees/import "$T/e2.csv" | code)" "PH-TENANT-002"
check "simulación por encima del límite -> PH-TENANT-002" "$(csv t "$CT" '/v1/employees/import?simular=1' "$T/e2.csv" | code)" "PH-TENANT-002"

echo "== Bienestar (fase 4)"
TOKE=$(send h POST "$CH" "/v1/employees/$EID/invitation" "{\"email\":\"emp$R@prueba.test\"}" | jq_ "d['data']['enlace'].split('/')[-1]")
curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKE\",\"password\":\"Empleado#Seguro1\",\"acepta_privacidad\":true}" > /dev/null
curl -s -c "$T/e" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"emp$R@prueba.test\",\"password\":\"Empleado#Seguro1\"}" > "$T/el.json"
check "login empleado" "$(jq_ "d['data']['usuario']['perfil']" < "$T/el.json")" "EMPLEADO"
CE=$(jq_ "d['data']['csrf_token']" < "$T/el.json")
check "check-in sin CSRF -> PH-AUTH-003" "$(curl -s -b "$T/e" -H "$J" "$B/v1/me/checkins" -d '{"animo":3,"energia":3,"estres":3}' | code)" "PH-AUTH-003"
send e POST "$CE" /v1/me/checkins '{"animo":2,"energia":2,"estres":5,"nota":"Nota privada de prueba"}' > "$T/ck.json"
check "check-in del día (RF-050)" "$(jq_ "d['data']['checkin']['indice']" < "$T/ck.json")" "17"
check "valores bajos -> mensaje de apoyo (RF-055)" "$(jq_ "d['data']['apoyo']['recursos'][0]['telefono']" < "$T/ck.json")" "024"
check "repetirlo actualiza el del día (RF-051)" "$(send e POST "$CE" /v1/me/checkins '{"animo":4,"energia":4,"estres":2,"nota":"Nota privada de prueba"}' | jq_ "str(d['data']['actualizado'])+' '+str(d['data']['apoyo'])")" "True None"
check "historial propio con la nota descifrada (RF-052, LEG-003)" "$(curl -s -b "$T/e" "$B/v1/me/checkins?days=30" | jq_ "str(d['meta']['hecho_hoy'])+' '+d['data'][0]['nota']")" "True Nota privada de prueba"
check "check-in de fecha futura -> PH-VAL-001" "$(send e POST "$CE" /v1/me/checkins '{"animo":3,"energia":3,"estres":3,"fecha":"2099-01-01"}' | code)" "PH-VAL-001"
check "escala fuera de 1-5 -> PH-VAL-001" "$(send e POST "$CE" /v1/me/checkins '{"animo":0,"energia":3,"estres":3}' | code)" "PH-VAL-001"
check "empleado no ve agregados -> PH-PERM-001" "$(curl -s -b "$T/e" "$B/v1/wellbeing/summary" | code)" "PH-PERM-001"
check "gestión no hace check-in -> PH-PERM-001" "$(curl -s -b "$T/h" "$B/v1/me/checkins" | code)" "PH-PERM-001"
curl -s -b "$T/h" "$B/v1/wellbeing/summary" > "$T/wb.json"
check "una sola persona: aviso de anonimato PH-WB-001 (LEG-002)" "$(jq_ "d['meta']['aviso']['code']" < "$T/wb.json")" "PH-WB-001"
check "por debajo del umbral no hay valores ni recuento" "$(jq_ "str(d['data']['actual'])+' '+str(d['data']['semanas'][-1]['indice'])+' '+str(d['data']['semanas'][-1]['participantes'])" < "$T/wb.json")" "None None None"
check "equipo de otra empresa -> PH-TENANT-001" "$(curl -s -b "$T/t" "$B/v1/wellbeing/summary?team_id=$EQN" | code)" "PH-TENANT-001"
check "alertas de la empresa" "$(curl -s -b "$T/h" "$B/v1/alerts" | jq_ "d['meta']['total']")" "0"
check "gestionar alerta inexistente -> PH-TENANT-001" "$(send h PATCH "$CH" /v1/alerts/999999 '{"estado":"GESTIONADA"}' | code)" "PH-TENANT-001"
check "empleado no ve alertas -> PH-PERM-001" "$(curl -s -b "$T/e" "$B/v1/alerts" | code)" "PH-PERM-001"

echo "== Formación (fase 5)"
check "catálogo global para la gestión (RF-040)" "$(curl -s -b "$T/h" "$B/v1/training/modules" | jq_ "d['meta']['total'] >= 15 and all(not m['propio'] for m in d['data'])")" "True"
check "catálogo publicado para el empleado" "$(curl -s -b "$T/e" "$B/v1/me/training/catalog" | jq_ "d['meta']['total'] >= 15")" "True"
check "empleado no gestiona formación -> PH-PERM-001" "$(curl -s -b "$T/e" "$B/v1/training/modules" | code)" "PH-PERM-001"
MG=$(curl -s -b "$T/h" "$B/v1/training/modules?category=CONDUCCION" | jq_ "d['data'][0]['id']")
check "editar un módulo global -> PH-PERM-001" "$(send g PATCH "$CG" "/v1/training/modules/$MG" '{"titulo":"Cambiado"}' | code)" "PH-PERM-001"
check "módulo propio con una pregunta -> PH-VAL-001" "$(send g POST "$CG" /v1/training/modules '{"titulo":"Mal","resumen":"Módulo de prueba incompleto","categoria":"ALMACEN","duracion_min":3,"contenido":{"objetivos":["X"],"bloques":[{"tipo":"consejo","texto":"Y"}]},"preguntas":[{"enunciado":"¿Z?","opciones":["a","b"],"correcta":0}]}' | code)" "PH-VAL-001"
send h POST "$CH" /v1/training/modules '{"titulo":"Normas del muelle","resumen":"Horarios y normas del muelle de carga","categoria":"ALMACEN","duracion_min":3,"puestos_destino":["CONDUCTOR"],"etiquetas":["acogida"],"contenido":{"objetivos":["Conocer el muelle"],"bloques":[{"tipo":"texto","titulo":"Horario","texto":"De 6 a 8."},{"tipo":"imagen","ilustracion":"ALMACEN","alt":"Muelle"}]},"preguntas":[{"enunciado":"¿Hora de apertura?","opciones":["6","9"],"correcta":0},{"enunciado":"¿Chaleco?","opciones":["No","Sí"],"correcta":1}]}' > "$T/mp.json"
check "RRHH crea un módulo propio en borrador (RF-046)" "$(jq_ "d['data']['estado']+' '+str(d['data']['propio'])" < "$T/mp.json")" "BORRADOR True"
MP=$(jq_ "d['data']['id']" < "$T/mp.json")
check "el borrador no está en el catálogo del empleado" "$(curl -s -b "$T/e" "$B/v1/me/training/catalog" | jq_ "any(m['id']==$MP for m in d['data'])")" "False"
check "publicar el módulo propio" "$(send h POST "$CH" "/v1/training/modules/$MP/publish" '{}' | jq_ "d['data']['estado']")" "PUBLICADO"
check "otra empresa no ve el módulo propio -> PH-TENANT-001" "$(curl -s -b "$T/t" "$B/v1/training/modules/$MP" | code)" "PH-TENANT-001"
check "otra empresa no puede asignarlo -> PH-TENANT-001" "$(send t POST "$CT" /v1/training/assignments "{\"modulo_id\":$MP,\"usuario_ids\":[$E2]}" | code)" "PH-TENANT-001"
check "asignar a un empleado de otra empresa -> PH-TENANT-001" "$(send t POST "$CT" /v1/training/assignments "{\"modulo_id\":$MG,\"usuario_ids\":[$EID]}" | code)" "PH-TENANT-001"
check "fecha límite pasada -> PH-VAL-001" "$(send h POST "$CH" /v1/training/assignments "{\"modulo_id\":$MP,\"usuario_ids\":[$EID],\"fecha_limite\":\"2020-01-01\"}" | code)" "PH-VAL-001"
check "asignar al equipo con fecha límite (RF-042)" "$(send h POST "$CH" /v1/training/assignments "{\"modulo_id\":$MP,\"equipo_id\":$EQN,\"fecha_limite\":\"2099-12-31\"}" | jq_ "d['data']['creadas'] >= 1")" "True"
curl -s -b "$T/e" "$B/v1/me/training" > "$T/mt.json"
check "el empleado ve su formación asignada" "$(jq_ "[a['origen'] for a in d['data'] if a['modulo']['id']==$MP][0]" < "$T/mt.json")" "GESTION"
AS=$(jq_ "[a['id'] for a in d['data'] if a['modulo']['id']==$MP][0]" < "$T/mt.json")
check "el reproductor no envía las soluciones" "$(curl -s -b "$T/e" "$B/v1/me/training/$AS" | jq_ "len(d['data']['modulo']['preguntas'])==2 and all('correcta' not in p for p in d['data']['modulo']['preguntas'])")" "True"
check "avance: en curso y nunca 100 sin aprobar" "$(send e POST "$CE" "/v1/me/training/$AS/progress" '{"bloque":2,"progreso_pct":100}' | jq_ "d['data']['estado']+' '+str(d['data']['progreso_pct'])")" "EN_CURSO 90"
check "suspenso con 50 % (RF-044)" "$(send e POST "$CE" "/v1/me/training/$AS/submit" '{"respuestas":[1,1],"envio_id":"smoke-envio-0001"}' | jq_ "str(d['data']['resultado']['aprobado'])+' '+str(d['data']['resultado']['nota'])")" "False 50"
check "reenvío desde la cola: no suma intento" "$(send e POST "$CE" "/v1/me/training/$AS/submit" '{"respuestas":[1,1],"envio_id":"smoke-envio-0001"}' | jq_ "str(d['data']['repetido'])+' '+str(d['data']['asignacion']['intentos'])")" "True 1"
check "respuestas incompletas -> PH-VAL-001" "$(send e POST "$CE" "/v1/me/training/$AS/submit" '{"respuestas":[0]}' | code)" "PH-VAL-001"
check "aprobado: completada con la mejor nota" "$(send e POST "$CE" "/v1/me/training/$AS/submit" '{"respuestas":[0,1],"envio_id":"smoke-envio-0002"}' | jq_ "d['data']['asignacion']['estado']+' '+str(d['data']['asignacion']['mejor_nota'])+' '+str(d['data']['asignacion']['intentos'])")" "COMPLETADA 100 2"
check "autoinscripción sin fecha límite (RF-047)" "$(send e POST "$CE" /v1/me/training/enroll "{\"modulo_id\":$MG}" | jq_ "d['data']['origen']+' '+str(d['data']['fecha_limite'])")" "AUTOINSCRIPCION None"
check "inscribirse dos veces -> PH-TRN-002" "$(send e POST "$CE" /v1/me/training/enroll "{\"modulo_id\":$MG}" | code)" "PH-TRN-002"
check "asignación que no es suya -> PH-TRN-001" "$(curl -s -b "$T/e" "$B/v1/me/training/999999" | code)" "PH-TRN-001"
check "recomendaciones por reglas (RF-043)" "$(curl -s -b "$T/h" "$B/v1/training/recommendations?employee_id=$EID" | jq_ "'ok' if 'por_regla' in d['meta'] else ''")" "ok"
check "seguimiento de la gestión" "$(curl -s -b "$T/h" "$B/v1/training/assignments?modulo_id=$MP&estado=COMPLETADA" | jq_ "d['meta']['total']")" "1"
check "resumen de formación" "$(curl -s -b "$T/g" "$B/v1/training/summary" | jq_ "d['data']['completadas']")" "1"

echo "== Asesor de formación con IA (fase 6)"
check "sin preferencias todavía" "$(curl -s -b "$T/e" "$B/v1/me/training/preferences" | jq_ "str(d['data'])+' '+str(len(d['meta']['opciones']['objetivos']))")" "None 5"
check "consulta sin preferencias -> PH-VAL-001" "$(send e POST "$CE" /v1/me/training/advisor '{}' | code)" "PH-VAL-001"
check "comentario con email -> PH-AI-004 (IA-010)" "$(send e PUT "$CE" /v1/me/training/preferences '{"objetivo":"SEGURIDAD","intereses":["CONDUCCION"],"minutos_semana":15,"comentario":"Escribidme a yo@correo.es"}' | code)" "PH-AI-004"
check "comentario con el nombre de alguien de la empresa -> PH-AI-004" "$(send e PUT "$CE" /v1/me/training/preferences '{"objetivo":"SEGURIDAD","intereses":["CONDUCCION"],"minutos_semana":15,"comentario":"Quiero hacer lo mismo que Lucía"}' | code)" "PH-AI-004"
check "comentario con datos de salud -> PH-AI-004" "$(send e PUT "$CE" /v1/me/training/preferences '{"objetivo":"SEGURIDAD","intereses":["CONDUCCION"],"minutos_semana":15,"comentario":"Tengo ansiedad al conducir"}' | code)" "PH-AI-004"
check "guardar preferencias (§12.2)" "$(send e PUT "$CE" /v1/me/training/preferences '{"objetivo":"SEGURIDAD","intereses":["CONDUCCION","ALMACEN"],"formato":"IMAGENES","minutos_semana":15,"comentario":"Quiero conducir más tranquilo en ciudad"}' | jq_ "d['data']['objetivo']+' '+str(d['data']['minutos_semana'])")" "SEGURIDAD 15"
send e POST "$CE" /v1/me/training/advisor '{}' > "$T/adv.json"
curl -s -b "$T/e" "$B/v1/me/training/catalog" > "$T/cat.json"
CATIDS=$(jq_ "','.join(str(m['id']) for m in d['data'])" < "$T/cat.json")
HECHOS=$(jq_ "','.join(str(m['id']) for m in d['data'] if m['asignacion'] and m['asignacion']['estado']=='COMPLETADA')" < "$T/cat.json")
check "3 a 5 recomendaciones del catálogo, sin completados (IA-001)" "$(jq_ "3 <= len(d['data']['recomendaciones']) <= 5 and all(str(r['modulo']['id']) in '$CATIDS'.split(',') and str(r['modulo']['id']) not in '$HECHOS'.split(',') for r in d['data']['recomendaciones']) and len(set(r['modulo']['id'] for r in d['data']['recomendaciones'])) == len(d['data']['recomendaciones'])" < "$T/adv.json")" "True"
check "indica de dónde sale la respuesta (IA-015, IA-018)" "$(jq_ "d['data']['servido_desde'] in ('live','cache','reglas') and isinstance(d['data']['generado_con_ia'], bool) and len(d['data']['plan_semanal']) > 0" < "$T/adv.json")" "True"
SOL=$(jq_ "d['data']['solicitud_id']" < "$T/adv.json")
LIBRE=$(jq_ "[r['modulo']['id'] for r in d['data']['recomendaciones'] if not r['asignacion']][0]" < "$T/adv.json")
check "valorar la recomendación" "$(send e POST "$CE" /v1/me/training/advisor/feedback "{\"solicitud_id\":$SOL,\"util\":true}" | jq_ "d['data']['util']")" "True"
check "valorar una consulta ajena -> PH-TENANT-001" "$(send e POST "$CE" /v1/me/training/advisor/feedback '{"solicitud_id":999999,"util":false}' | code)" "PH-TENANT-001"
check "inscribirse desde el asesor (origen ASESOR_IA)" "$(send e POST "$CE" /v1/me/training/enroll "{\"modulo_id\":$LIBRE,\"solicitud_id\":$SOL}" | jq_ "d['data']['origen']")" "ASESOR_IA"
check "la gestión no ve preferencias individuales -> PH-PERM-001 (IA-017)" "$(curl -s -b "$T/h" "$B/v1/me/training/preferences" | code)" "PH-PERM-001"
check "demanda con menos de 5 personas: oculta (IA-021, LEG-002)" "$(curl -s -b "$T/h" "$B/v1/training/demand" | jq_ "str(d['data']['disponible'])+' '+str(d['data']['personas'])+' '+d['meta']['aviso']['code']")" "False None PH-WB-001"
check "el empleado no ve la demanda -> PH-PERM-001" "$(curl -s -b "$T/e" "$B/v1/training/demand" | code)" "PH-PERM-001"
check "uso de IA para la plataforma (sin contenido)" "$(curl -s -b "$T/a" "$B/v1/admin/ai-usage" | jq_ "d['data']['consultas'] >= 1 and 'proveedor' in d['data']")" "True"
check "la gestión no ve el uso de IA de la plataforma -> PH-PERM-001" "$(curl -s -b "$T/h" "$B/v1/admin/ai-usage" | code)" "PH-PERM-001"
check "borrar mis preferencias (LEG-014)" "$(curl -s -b "$T/e" -X DELETE -H "X-CSRF-Token: $CE" -o /dev/null -w '%{http_code}' "$B/v1/me/training/preferences")" "204"

echo "== IA para RR. HH. · riesgo de rotación (fase 7)"
ALTA20=$(date -u -d '20 days ago' +%F)
send h POST "$CH" /v1/employees "{\"nombre\":\"Rosa\",\"apellidos\":\"Riesgo\",\"codigo_interno\":\"SMK-R\",\"puesto\":\"CONDUCTOR\",\"tipo_contrato\":\"TEMPORAL\",\"turno\":\"NOCHE\",\"fecha_alta\":\"$ALTA20\",\"equipo_id\":$EQN}" > "$T/er.json"
ER=$(jq_ "d['data']['id']" < "$T/er.json")
send h POST "$CH" "/v1/employees/$ER/incidents" "{\"tipo\":\"AUSENCIA_INJUSTIFICADA\",\"fecha\":\"$HOY\"}" > /dev/null
send h POST "$CH" "/v1/employees/$ER/incidents" "{\"tipo\":\"AUSENCIA_INJUSTIFICADA\",\"fecha\":\"$(date -u -d '3 days ago' +%F)\"}" > /dev/null
check "panel en plan Entrada, sin bloque de riesgo (RF-030)" "$(curl -s -b "$T/t" "$B/v1/dashboard/overview" | jq_ "str(d['data']['riesgo'])+' '+str(isinstance(d['data']['plantilla_activa'], int))")" "None True"
check "plan Entrada: ranking de riesgo -> PH-PLAN-001 (RF-010)" "$(curl -s -b "$T/t" "$B/v1/risk" | code)" "PH-PLAN-001"
check "plan Entrada: ficha de riesgo -> PH-PLAN-001" "$(curl -s -b "$T/t" "$B/v1/employees/$E2/risk" | code)" "PH-PLAN-001"
check "plan Entrada: línea base -> PH-PLAN-001" "$(curl -s -b "$T/t" "$B/v1/baseline" | code)" "PH-PLAN-001"
check "plan Entrada: tendencias -> PH-PLAN-001" "$(curl -s -b "$T/t" "$B/v1/dashboard/trends" | code)" "PH-PLAN-001"
check "el empleado no ve el riesgo -> PH-PERM-001" "$(curl -s -b "$T/e" "$B/v1/risk" | code)" "PH-PERM-001"
curl -s -b "$T/h" "$B/v1/risk" > "$T/rk.json"
check "ranking por puntuación con versión de reglas (RF-031, RF-032)" "$(jq_ "d['data'][0]['empleado']['id']==$ER and d['data'][0]['nivel']=='ALTO' and d['meta']['version_reglas']=='v1.1' and 'supervision' in d['meta']" < "$T/rk.json")" "True"
check "filtro por nivel" "$(curl -s -b "$T/h" "$B/v1/risk?level=ALTO" | jq_ "d['meta']['total']")" "1"
check "nivel no válido -> PH-VAL-001" "$(curl -s -b "$T/h" "$B/v1/risk?level=MUY_ALTO" | code)" "PH-VAL-001"
curl -s -b "$T/g" "$B/v1/employees/$ER/risk" > "$T/fr.json"
check "ficha explicada: 30+25+15+10 = 80 (RF-034)" "$(jq_ "str(d['data']['puntuacion'])+' '+','.join(f['codigo'] for f in d['data']['factores'])+' '+str(all(f['detalle'] and 'fuentes' in f for f in d['data']['factores']))" < "$T/fr.json")" "80 F1,F2,F3,F5 True"
check "la ficha remite a la metodología y avisa de la supervisión (LEG-005)" "$(jq_ "d['meta']['metodologia']+' '+str('conversación' in d['meta']['supervision'])+' '+str(len(d['data']['historico']) >= 1)" < "$T/fr.json")" "/v1/risk/methodology True True"
check "los retrasos no puntúan (§9.3)" "$(curl -s -b "$T/h" "$B/v1/employees/$EID/risk" | jq_ "str(d['data']['puntuacion'])+' '+d['data']['nivel']")" "0 BAJO"
check "paso a nivel alto -> alerta de rotación (RF-033)" "$(curl -s -b "$T/g" "$B/v1/alerts?tipo=RIESGO_ROTACION" | jq_ "str(d['meta']['total'])+' '+d['data'][0]['severidad']")" "1 ALTA"
check "ficha de otra empresa -> PH-TENANT-001" "$(curl -s -b "$T/g" "$B/v1/employees/$E2/risk" | code)" "PH-TENANT-001"
check "recalcular bajo demanda (RF-031)" "$(send h POST "$CH" /v1/risk/recalculate '{}' | jq_ "str(d['data']['personas'])+' '+str(d['data']['alto'])+' '+str(d['data']['alertas'])")" "4 1 0"
check "recalcular otra vez antes de 10 minutos -> PH-RATE-001" "$(send g POST "$CG" /v1/risk/recalculate '{}' | code)" "PH-RATE-001"
check "metodología: 7 factores, exclusiones y fuentes (LEG-006)" "$(curl -s -b "$T/h" "$B/v1/risk/methodology" | jq_ "str(len(d['data']['factores']))+' '+d['data']['version']+' '+d['data']['excluidos'][0]['nombre']+' '+str(len(d['data']['fuentes']))")" "7 v1.1 Retrasos 7"
check "revisión de sesgos por 4 dimensiones (LEG-015)" "$(curl -s -b "$T/h" "$B/v1/risk/bias" | jq_ "str(len(d['data']['dimensiones']))+' '+str(d['data']['umbral_pp'])")" "4 20"
check "panel Completo con riesgo y alertas (RF-030)" "$(curl -s -b "$T/g" "$B/v1/dashboard/overview" | jq_ "str(d['data']['riesgo']['alto'])+' '+str(d['data']['alertas_riesgo_abiertas'])+' '+str(d['data']['plantilla_activa'])")" "1 1 4"
check "tendencias de 6 meses" "$(curl -s -b "$T/g" "$B/v1/dashboard/trends?months=6" | jq_ "len(d['data'])")" "6"
check "tendencias fuera de rango -> PH-VAL-001" "$(curl -s -b "$T/g" "$B/v1/dashboard/trends?months=40" | code)" "PH-VAL-001"
check "línea base vacía con periodo sugerido (RF-037)" "$(curl -s -b "$T/h" "$B/v1/baseline" | jq_ "str(d['data']['linea_base'])+' '+str(d['meta']['sugerencia']['periodo_fin'] < '$HOY')")" "None True"
LB1=$(date -u -d '1 year ago' +%F); LB2=$(date -u -d '1 day ago' +%F)
check "línea base sin CSRF -> PH-AUTH-003" "$(curl -s -b "$T/h" -X PUT -H "$J" "$B/v1/baseline" -d '{}' | code)" "PH-AUTH-003"
check "línea base de 2 meses -> PH-VAL-001" "$(send h PUT "$CH" /v1/baseline "{\"periodo_inicio\":\"$(date -u -d '60 days ago' +%F)\",\"periodo_fin\":\"$LB2\",\"plantilla_media\":20,\"bajas_voluntarias\":5,\"dias_absentismo\":30}" | code)" "PH-VAL-001"
check "línea base con plantilla no numérica -> PH-VAL-001" "$(send h PUT "$CH" /v1/baseline "{\"periodo_inicio\":\"$LB1\",\"periodo_fin\":\"$LB2\",\"plantilla_media\":\"veinte\",\"bajas_voluntarias\":5,\"dias_absentismo\":30}" | code)" "PH-VAL-001"
check "registrar línea base: rotación 25 % y periodo corto" "$(send h PUT "$CH" /v1/baseline "{\"periodo_inicio\":\"$LB1\",\"periodo_fin\":\"$LB2\",\"plantilla_media\":20,\"bajas_voluntarias\":5,\"dias_absentismo\":30,\"fuente\":\"Nóminas 2025\"}" | jq_ "str(d['data']['linea_base']['rotacion_voluntaria'])+' '+str(d['data']['comparacion']['periodo_corto'])+' '+str(d['data']['comparacion']['objetivo_piloto'])")" "25 True -15"

echo "== Operación (fase 9): portabilidad y derechos RGPD"
curl -s -b "$T/e" -D "$T/de.h" "$B/v1/me/data-export" > "$T/de.json"
check "el empleado descarga sus datos con su nota descifrada (RGPD art. 15)" "$(jq_ "d['persona']['email']=='emp$R@prueba.test' and any(c['nota']=='Nota privada de prueba' for c in d['checkins_bienestar'])" < "$T/de.json") $(grep -ic 'content-disposition: attachment' "$T/de.h")" "True 1"
check "la cuenta guarda la versión de la privacidad aceptada (LEG-001)" "$(jq_ "d['persona']['privacidad_version'] == '$(curl -s "$B/v1/public/params" | jq_ "d['data']['legal.version']")'" < "$T/de.json")" "True"
curl -s -b "$T/g" -D "$T/ce.h" -o "$T/ce.zip" "$B/v1/company/export"
check "el gerente exporta su empresa en ZIP (RF-110)" "$(head -c 2 "$T/ce.zip") $(grep -ic 'content-type: application/zip' "$T/ce.h")" "PK 1"
check "el ZIP trae empleados sin notas del check-in" "$(python3 -c "import zipfile,sys;z=zipfile.ZipFile(sys.argv[1]);n=z.namelist();print('empleados.csv' in n and 'bienestar_semanal_por_equipo.csv' in n and b'Nota privada' not in b''.join(z.read(x) for x in n))" "$T/ce.zip")" "True"
check "RR. HH. no exporta la empresa -> PH-PERM-001" "$(curl -s -b "$T/h" "$B/v1/company/export" | code)" "PH-PERM-001"
check "la plataforma exporta una empresa" "$(curl -s -b "$T/a" -o /dev/null -w '%{http_code} %{content_type}' "$B/v1/admin/tenants/$EMP2/export")" "200 application/zip"
check "suprimir a alguien de alta -> PH-VAL-001 (antes, la baja)" "$(send g POST "$CG" "/v1/employees/$EID/erase" '{"confirmacion":"SUPRIMIR"}' | code)" "PH-VAL-001"

echo "== Baja laboral"
check "motivo no válido -> PH-VAL-001" "$(send h DELETE "$CH" "/v1/employees/$EID" "{\"fecha_baja\":\"$HOY\",\"motivo_baja\":\"DESPIDO\"}" | code)" "PH-VAL-001"
check "baja con motivo y sin acceso" "$(send h DELETE "$CH" "/v1/employees/$EID" "{\"fecha_baja\":\"$HOY\",\"motivo_baja\":\"VOLUNTARIA\"}" | campos 'motivo_baja estado_acceso')" "VOLUNTARIA BAJA"
check "ficha de baja en solo lectura -> PH-VAL-001" "$(send h PATCH "$CH" "/v1/employees/$EID" '{"turno":"NOCHE"}' | code)" "PH-VAL-001"
check "la baja sale de la lista de altas" "$(total h /v1/employees)" "3"
check "y aparece en las bajas" "$(total h '/v1/employees?situacion=BAJA')" "1"
check "supresión sin confirmar -> PH-VAL-001" "$(send g POST "$CG" "/v1/employees/$EID/erase" '{"confirmacion":"si"}' | code)" "PH-VAL-001"
check "RR. HH. no suprime -> PH-PERM-001" "$(send h POST "$CH" "/v1/employees/$EID/erase" '{"confirmacion":"SUPRIMIR"}' | code)" "PH-PERM-001"
check "suprimir los datos de la persona (RGPD art. 17)" "$(curl -s -b "$T/g" -X POST -H "$J" -H "X-CSRF-Token: $CG" -o /dev/null -w '%{http_code}' "$B/v1/employees/$EID/erase" -d '{"confirmacion":"SUPRIMIR"}')" "204"
check "la persona suprimida ya no aparece" "$(curl -s -b "$T/h" "$B/v1/employees/$EID" | code) $(total h '/v1/employees?situacion=BAJA')" "PH-TENANT-001 0"
check "la rotación de la empresa la sigue contando" "$(curl -s -b "$T/g" "$B/v1/dashboard/overview" | jq_ "d['data']['bajas_12m']")" "1"

echo "== Operación (fase 9): baja de empresa, mantenimiento y errores"
check "baja de la empresa con fecha de borrado (RF-111, LEG-017)" "$(send a PATCH "$CA" "/v1/admin/tenants/$EMP2" '{"estado":"BAJA"}' | jq_ "d['data']['estado']+' '+str(d['data']['fecha_borrado'] is not None)")" "BAJA True"
check "empresa de baja: solo lectura -> PH-TENANT-004" "$(send t POST "$CT" /v1/teams '{"nombre":"Nuevo"}' | code)" "PH-TENANT-004"
check "empresa de baja: puede consultar y exportar" "$(curl -s -b "$T/t" "$B/v1/auth/me" | jq_ "str(d['data']['interlocutor']['fecha_borrado'] is not None)") $(curl -s -b "$T/t" -o /dev/null -w '%{http_code}' "$B/v1/company/export")" "True 200"
check "reactivar la empresa quita la fecha de borrado" "$(send a PATCH "$CA" "/v1/admin/tenants/$EMP2" '{"estado":"ACTIVO"}' | jq_ "str(d['data']['fecha_borrado'])")" "None"
FUT1=$(date -d '+2 hours' +%Y-%m-%dT%H:%M); FUT2=$(date -d '+3 hours' +%Y-%m-%dT%H:%M)
check "programar un mantenimiento (RNF-063)" "$(send a PUT "$CA" /v1/admin/maintenance "{\"activo\":false,\"mensaje\":\"Actualización de prueba\",\"programado_inicio\":\"$FUT1\",\"programado_fin\":\"$FUT2\"}" | jq_ "str(d['data']['activo'])+' '+str(d['data']['programado'] is not None)")" "False True"
check "aviso público del mantenimiento programado" "$(curl -s "$B/v1/public/status" | jq_ "str(d['data']['mantenimiento'])+' '+d['data']['programado']['mensaje']")" "False Actualización de prueba"
check "ventana con el fin antes del inicio -> PH-VAL-001" "$(send a PUT "$CA" /v1/admin/maintenance "{\"activo\":false,\"programado_inicio\":\"$FUT2\",\"programado_fin\":\"$FUT1\"}" | code)" "PH-VAL-001"
send a PUT "$CA" /v1/admin/maintenance '{"activo":true,"mensaje":"Volvemos en 5 minutos"}' > /dev/null
check "mantenimiento activo: la app responde PH-SYS-003" "$(curl -s -b "$T/h" "$B/v1/teams" | jq_ "d['error']['code']+' '+d['error']['message']")" "PH-SYS-003 Volvemos en 5 minutos"
check "mantenimiento activo: la plataforma y la salud siguen" "$(curl -s -b "$T/a" -o /dev/null -w '%{http_code}' "$B/v1/admin/maintenance") $(curl -s -o /dev/null -w '%{http_code}' "$B/v1/health")" "200 200"
check "desactivar el mantenimiento" "$(send a PUT "$CA" /v1/admin/maintenance '{"activo":false}' | jq_ "str(d['data']['activo'])+' '+str(d['data']['programado'])") $(curl -s -b "$T/h" -o /dev/null -w '%{http_code}' "$B/v1/teams")" "False None 200"
check "la gestión no toca el mantenimiento -> PH-PERM-001" "$(curl -s -b "$T/h" "$B/v1/admin/maintenance" | code)" "PH-PERM-001"
check "resumen de errores 5xx (RNF-062)" "$(curl -s -b "$T/a" "$B/v1/admin/errors?hours=24" | jq_ "str(d['data']['horas'])+' '+str(isinstance(d['data']['grupos'], list))")" "24 True"

echo "== Parámetros, textos legales e IVA (fase 9a)"
check "parámetros públicos: titular pendiente de constitución" "$(curl -s "$B/v1/public/params" | jq_ "str(d['data']['titular.constituida'])+' '+d['data']['titular.email']")" "False info@hyo.company"
check "ejemplo mensual: base, IVA 21 % y total" "$(curl -s "$B/v1/public/params" | jq_ "' '.join(str(d['data']['ejemplos']['ENTRADA']['MENSUAL'][k]) for k in ('base','iva_pct','iva','total'))")" "15 21 3.15 18.15"
check "ejemplo anual sin descuento (12 meses)" "$(curl -s "$B/v1/public/params" | jq_ "' '.join(str(d['data']['ejemplos']['COMPLETO']['ANUAL'][k]) for k in ('base','descuento','total'))")" "348 0 421.08"
check "los parámetros públicos no exponen los días de gracia" "$(curl -s "$B/v1/public/params" | jq_ "str('suscripcion.dias_gracia' in d['data'])")" "False"
check "la plataforma lista los parámetros" "$(curl -s -b "$T/a" "$B/v1/admin/params" | jq_ "str(len(d['data']) >= 18)+' '+str(any(p['clave']=='precios.descuento_anual_pct' for p in d['data']))")" "True True"
check "descuento anual del 10 % -> ejemplo anual con descuento" "$(send a PUT "$CA" /v1/admin/params '{"precios.descuento_anual_pct":10}' > /dev/null; curl -s "$B/v1/public/params" | jq_ "' '.join(str(d['data']['ejemplos']['ENTRADA']['ANUAL'][k]) for k in ('descuento','base','total'))")" "18 162 196.02"
check "descuento fuera de rango -> PH-VAL-001 con el campo" "$(send a PUT "$CA" /v1/admin/params '{"precios.descuento_anual_pct":80}' | jq_ "d['error']['code']+' '+str('precios.descuento_anual_pct' in d['error'].get('details',{}))")" "PH-VAL-001 True"
check "parámetro desconocido -> PH-VAL-001" "$(send a PUT "$CA" /v1/admin/params '{"precios.otro":1}' | code)" "PH-VAL-001"
check "tipo incorrecto -> PH-VAL-001" "$(send a PUT "$CA" /v1/admin/params '{"titular.constituida":"si"}' | code)" "PH-VAL-001"
send a PUT "$CA" /v1/admin/params '{"precios.descuento_anual_pct":0}' > /dev/null
check "la gestión no cambia parámetros -> PH-PERM-001" "$(send h PUT "$CH" /v1/admin/params '{"precios.iva_pct":10}' | code)" "PH-PERM-001"
check "parámetros sin CSRF -> PH-AUTH-003" "$(curl -s -b "$T/a" -X PUT -H "$J" "$B/v1/admin/params" -d '{"precios.iva_pct":21}' | code)" "PH-AUTH-003"
check "/legal/privacidad en la web" "$(curl -s -o /dev/null -w '%{http_code}' "$WEB/js/views/public/legal.js")" "200"

echo "== Vigencia de la suscripción (fase 9b)"
check "empresa nueva en prueba gratuita" "$(curl -s -b "$T/t" "$B/v1/auth/me" | jq_ "d['data']['vigencia']['estado']+' '+str(d['data']['vigencia']['dias_restantes'] >= 28)")" "PRUEBA True"
check "resumen de la suscripción con importes (base + IVA)" "$(curl -s -b "$T/t" "$B/v1/billing/subscription" | jq_ "d['data']['plan']+' '+str(d['data']['importes']['ANUAL']['iva_pct'])+' '+str(d['data']['importes']['MENSUAL']['total'] > d['data']['importes']['MENSUAL']['base'])")" "ENTRADA 21 True"
check "RR. HH. no ve la facturación -> PH-PERM-001" "$(curl -s -b "$T/h" "$B/v1/billing/subscription" | code)" "PH-PERM-001"
check "periodo vencido hace 3 días -> en gracia" "$(send a PUT "$CA" "/v1/admin/tenants/$EMP2/validity" "{\"accion\":\"FIJAR\",\"periodo_fin\":\"$(date -d '-3 days' +%F)\",\"vigencia\":\"VIGENTE\"}" | jq_ "d['data']['estado']+' '+str(d['data']['solo_lectura'])")" "GRACIA False"
check "en gracia se puede seguir trabajando" "$(send t POST "$CT" /v1/teams "{\"nombre\":\"Gracia $R\"}" | jq_ "'ok' if 'data' in d else d['error']['code']")" "ok"
send a PUT "$CA" "/v1/admin/tenants/$EMP2/validity" "{\"accion\":\"FIJAR\",\"periodo_fin\":\"$(date -d '-20 days' +%F)\",\"vigencia\":\"VIGENTE\"}" > /dev/null
check "tras la gracia -> vencida y solo lectura" "$(curl -s -b "$T/t" "$B/v1/auth/me" | jq_ "d['data']['vigencia']['estado']+' '+str(d['data']['vigencia']['solo_lectura'])")" "VENCIDA True"
check "vencida: escribir -> PH-PLAN-002" "$(send t POST "$CT" /v1/teams '{"nombre":"Bloqueado"}' | code)" "PH-PLAN-002"
check "vencida: consultar y exportar siguen" "$(curl -s -b "$T/t" -o /dev/null -w '%{http_code}' "$B/v1/teams") $(curl -s -b "$T/t" -o /dev/null -w '%{http_code}' "$B/v1/company/export") $(curl -s -b "$T/t" -o /dev/null -w '%{http_code}' "$B/v1/billing/subscription")" "200 200 200"
check "renovar 30 días con pago manual (base + IVA)" "$(send a PUT "$CA" "/v1/admin/tenants/$EMP2/validity" '{"accion":"AMPLIAR","dias":30,"pago_manual":{"base":100,"nota":"Transferencia de prueba"}}' | jq_ "d['data']['estado']+' '+str(d['data']['dias_restantes'] >= 29)")" "VIGENTE True"
check "el pago manual aparece con el IVA desglosado" "$(curl -s -b "$T/t" "$B/v1/billing/invoices" | jq_ "' '.join(str(d['data'][0][k]) for k in ('origen','base','iva','total'))")" "MANUAL 100 21 121"
check "la plataforma lista los pagos" "$(curl -s -b "$T/a" "$B/v1/admin/payments?tenant=$EMP2" | jq_ "str(d['meta']['total'] >= 1)+' '+str(d['meta']['cobrado'] >= 121)")" "True True"
check "vigencia en el listado de empresas" "$(curl -s -b "$T/a" "$B/v1/admin/tenants/$EMP2" | jq_ "d['data']['vigencia']['estado']")" "VIGENTE"
check "acción de vigencia no válida -> PH-VAL-001" "$(send a PUT "$CA" "/v1/admin/tenants/$EMP2/validity" '{"accion":"REGALAR"}' | code)" "PH-VAL-001"
check "la gestión no cambia la vigencia -> PH-PERM-001" "$(send t PUT "$CT" "/v1/admin/tenants/$EMP2/validity" '{"accion":"SIN_VENCIMIENTO"}' | code)" "PH-PERM-001"

echo "== Pagos con Stripe en modo prueba (fase 9c)"
check "contratar sin aceptar los términos -> PH-VAL-001" "$(send t POST "$CT" /v1/billing/checkout '{"plan":"ENTRADA","ciclo":"MENSUAL","acepta_terminos":false}' | code)" "PH-VAL-001"
check "webhook sin firma -> PH-PAY-005" "$(curl -s -H "$J" "$B/v1/stripe/webhook" -d '{"id":"evt_x","type":"invoice.paid","data":{"object":{}}}' | code)" "PH-PAY-005"
if [ -n "${SMOKE_STRIPE_WHSEC:-}" ]; then
  firmar(){ local ts; ts=$(date +%s); printf 't=%s,v1=%s' "$ts" "$(printf '%s.%s' "$ts" "$1" | openssl dgst -sha256 -hmac "$SMOKE_STRIPE_WHSEC" | awk '{print $NF}')"; }
  webhook(){ curl -s -H "$J" -H "Stripe-Signature: $(firmar "$1")" "$B/v1/stripe/webhook" --data-binary "$1"; }
  send t POST "$CT" /v1/billing/checkout '{"plan":"COMPLETO","ciclo":"ANUAL","acepta_terminos":true}' > "$T/co.json"
  check "Checkout: URL de Stripe e importe con IVA" "$(jq_ "str(d['data']['url'].startswith('https://'))+' '+d['data']['importe']['ciclo']+' '+str(d['data']['importe']['iva_pct'])" < "$T/co.json")" "True ANUAL 21"
  if [ -n "${SMOKE_STRIPE_MOCK:-}" ]; then
    curl -s "$SMOKE_STRIPE_MOCK/__mock/state" > "$T/ms.json"
    check "Checkout: precio sin IVA + tasa de IVA exclusiva, por empleado y año" "$(jq_ "' '.join(str(x) for x in (d['sesiones'][-1]['params']['line_items'][0]['price_data']['recurring']['interval'], d['sesiones'][-1]['params']['line_items'][0]['price_data']['unit_amount'], d['tasas'][d['sesiones'][-1]['params']['line_items'][0]['tax_rates'][0]]['percentage'], d['tasas'][d['sesiones'][-1]['params']['line_items'][0]['tax_rates'][0]]['inclusive'], d['sesiones'][-1]['params']['metadata']['plan']))" < "$T/ms.json")" "year 34800 21 False COMPLETO"
    CUS=$(jq_ "d['sesiones'][-1]['params']['customer']" < "$T/ms.json"); SUB=$(jq_ "d['sesiones'][-1]['subscription_simulada']" < "$T/ms.json")
    check "webhook con firma de otro secreto -> PH-PAY-005" "$(curl -s -H "$J" -H "Stripe-Signature: t=$(date +%s),v1=$(printf 'x' | openssl dgst -sha256 -hmac otro | awk '{print $NF}')" "$B/v1/stripe/webhook" -d '{"id":"evt_y"}' | code)" "PH-PAY-005"
    EV1="{\"id\":\"evt_cs_$R\",\"type\":\"checkout.session.completed\",\"livemode\":false,\"data\":{\"object\":{\"id\":\"cs_x\",\"mode\":\"subscription\",\"customer\":\"$CUS\",\"subscription\":\"$SUB\",\"client_reference_id\":\"$EMP2\",\"metadata\":{\"plan\":\"COMPLETO\",\"ciclo\":\"ANUAL\"}}}}"
    check "webhook checkout.session.completed firmado" "$(webhook "$EV1" | jq_ "d['data']['resultado']")" "ok"
    check "el mismo evento otra vez -> duplicado (idempotencia)" "$(webhook "$EV1" | jq_ "d['data']['resultado']")" "duplicado"
    check "tras el Checkout: plan Completo anual con pago online" "$(curl -s -b "$T/t" "$B/v1/billing/subscription" | jq_ "d['data']['plan']+' '+d['data']['ciclo_cobro']+' '+str(d['data']['pago_online']['suscripcion'])")" "COMPLETO ANUAL True"
    P0=$(date -d '-1 day' +%s); P1=$(date -d '+364 days' +%s)
    EV2="{\"id\":\"evt_inv_$R\",\"type\":\"invoice.paid\",\"livemode\":false,\"data\":{\"object\":{\"id\":\"in_$R\",\"customer\":\"$CUS\",\"number\":\"PH-$R\",\"billing_reason\":\"subscription_create\",\"currency\":\"eur\",\"subtotal\":34800,\"total_excluding_tax\":34800,\"total\":42108,\"hosted_invoice_url\":\"https://invoice.stripe.test/i/$R\",\"invoice_pdf\":\"https://invoice.stripe.test/i/$R/pdf\",\"parent\":{\"subscription_details\":{\"subscription\":\"$SUB\"}},\"lines\":{\"data\":[{\"quantity\":1,\"period\":{\"start\":$P0,\"end\":$P1}}]}}}}"
    check "webhook invoice.paid -> pago con base, IVA y total" "$(webhook "$EV2" > /dev/null; curl -s -b "$T/t" "$B/v1/billing/invoices" | jq_ "' '.join(str(d['data'][0][k]) for k in ('origen','estado','base','iva','total'))")" "STRIPE PAGADO 348 73.08 421.08"
    check "tras el cobro: vigente hasta el fin del periodo pagado" "$(curl -s -b "$T/t" "$B/v1/auth/me" | jq_ "d['data']['vigencia']['estado']+' '+d['data']['vigencia']['periodo_fin']")" "VIGENTE $(date -d '+364 days' +%F)"
    check "con el pago online activo, otro Checkout -> PH-PAY-003" "$(send t POST "$CT" /v1/billing/checkout '{"plan":"ENTRADA","ciclo":"MENSUAL","acepta_terminos":true}' | code)" "PH-PAY-003"
    check "portal del cliente de Stripe" "$(send t POST "$CT" /v1/billing/portal '{}' | jq_ "str(d['data']['url'].startswith('https://'))")" "True"
    EV3="{\"id\":\"evt_fail_$R\",\"type\":\"invoice.payment_failed\",\"livemode\":false,\"data\":{\"object\":{\"id\":\"in_f$R\",\"customer\":\"$CUS\",\"currency\":\"eur\",\"subtotal\":34800,\"total\":42108,\"lines\":{\"data\":[{\"quantity\":1,\"period\":{\"start\":$P1,\"end\":$P1}}]}}}}"
    check "webhook invoice.payment_failed -> pago fallido registrado" "$(webhook "$EV3" > /dev/null; curl -s -b "$T/a" "$B/v1/admin/payments?tenant=$EMP2" | jq_ "str(any(p['estado']=='FALLIDO' for p in d['data']))")" "True"
    EV4="{\"id\":\"evt_del_$R\",\"type\":\"customer.subscription.deleted\",\"livemode\":false,\"data\":{\"object\":{\"id\":\"$SUB\",\"customer\":\"$CUS\"}}}"
    check "webhook subscription.deleted: deja de renovarse, la vigencia sigue" "$(webhook "$EV4" > /dev/null; curl -s -b "$T/t" "$B/v1/billing/subscription" | jq_ "str(d['data']['pago_online']['suscripcion'])+' '+d['data']['vigencia']['estado']")" "False VIGENTE"
    check "evento de modo real -> ignorado" "$(webhook "{\"id\":\"evt_live_$R\",\"type\":\"invoice.paid\",\"livemode\":true,\"data\":{\"object\":{}}}" | jq_ "d['data']['resultado']")" "ignorado_livemode"
  fi
else
  # Sin Stripe: PH-PAY-001. Con las claves de prueba reales: una URL de Checkout (que nadie paga).
  check "Checkout: pago online desactivado o URL de Stripe" "$(send t POST "$CT" /v1/billing/checkout '{"plan":"ENTRADA","ciclo":"MENSUAL","acepta_terminos":true}' | jq_ "'controlado' if d.get('error',{}).get('code') == 'PH-PAY-001' or str(d.get('data',{}).get('url','')).startswith('https://checkout.stripe.com') else json.dumps(d)[:120]")" "controlado"
fi

echo "== Pyme Hub Skills (fase 9d)"
# Una persona empleada nueva (la del bienestar ya se suprimió en las pruebas RGPD)
EID2=$(send g POST "$CG" /v1/employees '{"nombre":"Sara","apellidos":"Skills","puesto":"REPARTIDOR","tipo_contrato":"INDEFINIDO","turno":"MANANA","fecha_alta":"2025-02-01"}' | jq_ "d['data']['id']")
TOKE2=$(send g POST "$CG" "/v1/employees/$EID2/invitation" "{\"email\":\"emp2$R@prueba.test\"}" | jq_ "d['data']['enlace'].split('/')[-1]")
curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKE2\",\"password\":\"Empleado#Seguro2\",\"acepta_privacidad\":true}" > /dev/null
curl -s -c "$T/e2" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"emp2$R@prueba.test\",\"password\":\"Empleado#Seguro2\"}" > "$T/e2l.json"
CE2=$(jq_ "d['data']['csrf_token']" < "$T/e2l.json")
NIF3="B$(printf '%08d' $(((R+13) % 100000000)))"
send a POST "$CA" /v1/admin/trainers "{\"nombre\":\"Formación Prueba $R\",\"nif\":\"$NIF3\",\"formador\":{\"nombre\":\"Marta\",\"apellidos\":\"Prueba\",\"email\":\"formador$R@prueba.test\"}}" > "$T/fo.json"
check "la plataforma da de alta un formador con invitación" "$(jq_ "str(d['data']['invitacion']['enlace'] is not None)" < "$T/fo.json")" "True"
TOKF=$(jq_ "d['data']['invitacion']['enlace'].split('/')[-1]" < "$T/fo.json")
curl -s -H "$J" "$B/v1/auth/invitations/accept" -d "{\"token\":\"$TOKF\",\"password\":\"Formador#Seguro1\",\"acepta_privacidad\":true}" > /dev/null
curl -s -c "$T/f" -H "$J" "$B/v1/auth/login" -d "{\"email\":\"formador$R@prueba.test\",\"password\":\"Formador#Seguro1\"}" > "$T/fl.json"
CF=$(jq_ "d['data']['csrf_token']" < "$T/fl.json")
check "login formador sin plan ni funcionalidades de empresa" "$(jq_ "d['data']['usuario']['perfil']+' '+str(d['data']['features'])" < "$T/fl.json")" "FORMADOR []"
check "el formador no entra en la gestión de empresas -> PH-PERM-001" "$(curl -s -b "$T/f" "$B/v1/teams" | code) $(curl -s -b "$T/f" "$B/v1/skills/catalog" | code)" "PH-PERM-001 PH-PERM-001"
check "la gestión no entra en el panel del formador -> PH-PERM-001" "$(curl -s -b "$T/g" "$B/v1/skills/my-courses" | code)" "PH-PERM-001"
send f POST "$CF" /v1/skills/my-courses '{"titulo":"Carga segura de la furgoneta","resumen":"Cómo repartir el peso y sujetar la carga antes de salir a ruta","categoria":"ALMACEN","duracion_min":6,"puestos_destino":["CONDUCTOR","REPARTIDOR"],"etiquetas":["carga"],"contenido":{"objetivos":["Repartir el peso"],"bloques":[{"tipo":"texto","titulo":"Peso","texto":"Lo pesado abajo y delante."}]},"preguntas":[{"enunciado":"¿Dónde va lo pesado?","opciones":["Arriba","Abajo"],"correcta":1},{"enunciado":"¿Se sujeta la carga?","opciones":["Sí","No"],"correcta":0}]}' > "$T/sk.json"
SK=$(jq_ "d['data']['id']" < "$T/sk.json")
check "el formador crea un curso con el editor de módulos" "$(jq_ "str(d['data']['propio'])+' '+str(d['data']['skills'])" < "$T/sk.json")" "True True"
check "enviar a revisión sin publicar ni precios -> PH-VAL-001" "$(send f POST "$CF" "/v1/skills/my-courses/$SK/submit" '{}' | jq_ "d['error']['code']+' '+str(sorted(d['error']['details'].keys()))")" "PH-VAL-001 ['modulo', 'precios']"
check "precios: empresa sin IVA y persona con IVA incluido" "$(send f PUT "$CF" "/v1/skills/my-courses/$SK/listing" '{"precio_empresa":100,"precio_persona":12.1}' | jq_ "' '.join(str(x) for x in (d['data']['ficha']['importes']['EMPRESA']['total'], d['data']['ficha']['importes']['PERSONA']['base'], d['data']['ficha']['importes']['EMPRESA']['importe_formador']))")" "121 10 80"
send f POST "$CF" "/v1/skills/my-courses/$SK/publish" '{}' > /dev/null
check "publicado y enviado a revisión" "$(send f POST "$CF" "/v1/skills/my-courses/$SK/submit" '{}' | jq_ "d['data']['ficha']['estado']")" "PENDIENTE"
check "pendiente: no aparece en el catálogo" "$(curl -s -b "$T/g" "$B/v1/skills/catalog" | jq_ "str(any(c['id']==$SK for c in d['data']))")" "False"
check "la plataforma ve el curso pendiente con su contenido" "$(curl -s -b "$T/a" "$B/v1/admin/skills/courses?estado=PENDIENTE" | jq_ "str(any(c['id']==$SK for c in d['data']))") $(curl -s -b "$T/a" "$B/v1/admin/skills/courses/$SK" | jq_ "len(d['data']['preguntas'])")" "True 2"
check "rechazar sin motivo -> PH-VAL-001" "$(send a POST "$CA" "/v1/admin/skills/courses/$SK/review" '{"decision":"RECHAZAR"}' | code)" "PH-VAL-001"
check "la plataforma aprueba el curso" "$(send a POST "$CA" "/v1/admin/skills/courses/$SK/review" '{"decision":"APROBAR"}' | jq_ "d['data']['estado']")" "APROBADO"
check "catálogo de la gerencia: + IVA; del empleado: IVA incluido" "$(curl -s -b "$T/g" "$B/v1/skills/catalog" | jq_ "[c for c in d['data'] if c['id']==$SK][0]['importes']['EMPRESA']['total']") $(curl -s -b "$T/e2" "$B/v1/skills/catalog" | jq_ "[c for c in d['data'] if c['id']==$SK][0]['importes']['PERSONA']['total']")" "121 12.1"
check "editar el contenido de un curso aprobado lo devuelve a revisión" "$(send f PATCH "$CF" "/v1/skills/my-courses/$SK" '{"resumen":"Cómo repartir el peso y sujetar bien la carga antes de salir"}' > /dev/null; curl -s -b "$T/f" "$B/v1/skills/my-courses" | jq_ "[c for c in d['data'] if c['id']==$SK][0]['estado']")" "PENDIENTE"
send a POST "$CA" "/v1/admin/skills/courses/$SK/review" '{"decision":"APROBAR"}' > /dev/null
check "comprar sin aceptar las condiciones -> PH-VAL-001" "$(send g POST "$CG" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":false}" | code)" "PH-VAL-001"
check "persona: sin renunciar al desistimiento -> PH-VAL-001" "$(send e2 POST "$CE2" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true}" | jq_ "d['error']['code']+' '+str('renuncia_desistimiento' in d['error']['details'])")" "PH-VAL-001 True"
check "RR. HH. no compra -> PH-PERM-001" "$(send h POST "$CH" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true}" | code)" "PH-PERM-001"
if [ -n "${SMOKE_STRIPE_WHSEC:-}" ] && [ -n "${SMOKE_STRIPE_MOCK:-}" ]; then
  check "formador sin cuenta conectada: no se puede comprar -> PH-SKL-002" "$(send g POST "$CG" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true}" | code)" "PH-SKL-002"
  check "alta del formador en Stripe Connect (enlace)" "$(send f POST "$CF" /v1/skills/connect '{}' | jq_ "str(d['data']['url'].startswith('https://'))") $(curl -s -b "$T/f" "$B/v1/skills/connect" | jq_ "str(d['data']['cuenta'])+'/'+str(d['data']['lista'])")" "True True/False"
  curl -s -X POST "$SMOKE_STRIPE_MOCK/__mock/accounts/complete" > /dev/null
  check "cuenta conectada lista tras el alta" "$(curl -s -b "$T/f" "$B/v1/skills/connect" | jq_ "str(d['data']['lista'])")" "True"
  send g POST "$CG" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true}" > "$T/cp.json"
  check "compra de empresa: Checkout con el importe con IVA" "$(jq_ "str(d['data']['url'].startswith('https://'))+' '+str(d['data']['importe']['total'])" < "$T/cp.json")" "True 121"
  curl -s "$SMOKE_STRIPE_MOCK/__mock/state" > "$T/ms2.json"
  check "cargo con destino: el formador recibe el 80 % de la base" "$(jq_ "' '.join(str(x) for x in (d['sesiones'][-1]['params']['mode'], d['sesiones'][-1]['params']['payment_intent_data']['application_fee_amount'], d['sesiones'][-1]['params']['payment_intent_data']['transfer_data']['destination'][:5], d['tasas'][d['sesiones'][-1]['params']['line_items'][0]['tax_rates'][0]]['inclusive']))" < "$T/ms2.json")" "payment 4100 acct_ False"
  CPID=$(jq_ "d['data']['compra_id']" < "$T/cp.json"); CSID=$(jq_ "d['sesiones'][-1]['id']" < "$T/ms2.json")
  EV5="{\"id\":\"evt_sk_$R\",\"type\":\"checkout.session.completed\",\"livemode\":false,\"data\":{\"object\":{\"id\":\"$CSID\",\"mode\":\"payment\",\"payment_status\":\"paid\",\"payment_intent\":\"pi_$R\",\"metadata\":{\"compra_skills_id\":\"$CPID\"}}}}"
  check "webhook: compra pagada" "$(webhook "$EV5" | jq_ "d['data']['resultado']")" "ok"
  check "la empresa ya puede asignar el curso comprado" "$(curl -s -b "$T/g" "$B/v1/training/modules" | jq_ "str(any(m['id']==$SK and m['skills'] and not m['propio'] for m in d['data']))") $(curl -s -b "$T/g" "$B/v1/skills/catalog" | jq_ "str([c for c in d['data'] if c['id']==$SK][0]['licencia_empresa'])")" "True True"
  check "con licencia de empresa, otra compra -> PH-SKL-003" "$(send g POST "$CG" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true}" | code) $(send e2 POST "$CE2" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true,\"renuncia_desistimiento\":true}" | code)" "PH-SKL-003 PH-SKL-003"
  check "ventas del formador: 80 € para él, 20 € de comisión" "$(curl -s -b "$T/f" "$B/v1/skills/sales" | jq_ "' '.join(str(d['meta']['totales'][k]) for k in ('ventas','importe_formador','comision'))")" "1 80 20"
  check "la otra empresa no ve el curso comprado" "$(curl -s -b "$T/t" "$B/v1/training/modules" | jq_ "str(any(m['id']==$SK for m in d['data']))")" "False"
  check "la plataforma ve ventas y formadores" "$(curl -s -b "$T/a" "$B/v1/admin/skills/sales" | jq_ "str(d['meta']['totales']['ventas'] >= 1)") $(curl -s -b "$T/a" "$B/v1/admin/trainers" | jq_ "str(any(f['ventas']==1 and f['stripe']['lista'] for f in d['data']))")" "True True"
else
  check "compra sin pasarela o sin cuenta del formador: controlado" "$(send g POST "$CG" /v1/skills/purchases "{\"modulo_id\":$SK,\"acepta_condiciones\":true}" | jq_ "'controlado' if d.get('error',{}).get('code') in ('PH-PAY-001','PH-SKL-002') else json.dumps(d)[:120]")" "controlado"
fi

echo "== Diagnóstico del despliegue"
curl -s -b "$T/a" "$B/v1/admin/diagnostics" > "$T/dg.json"
check "la plataforma lanza el diagnóstico (grupos y resumen)" "$(jq_ "str(d['data']['estado'] in ('OK','AVISO','ERROR'))+' '+str(len(d['data']['comprobaciones']) >= 30)+' '+str(any(c['nombre']=='Migraciones de la base de datos' and c['estado']=='OK' for c in d['data']['comprobaciones']))" < "$T/dg.json")" "True True True"
check "el diagnóstico no contiene secretos" "$(grep -cE 'sk_test_[A-Za-z0-9]{6,}|whsec_[A-Za-z0-9]{6,}|password|Dev#Pass|BASE64' "$T/dg.json")" "0"
check "la gestión no lanza el diagnóstico -> PH-PERM-001" "$(curl -s -b "$T/g" "$B/v1/admin/diagnostics" | code)" "PH-PERM-001"

echo "== Demo comercial y QR del folleto (REQ_QR_DEMO_COMERCIAL)"
RD=$(curl -s -o /dev/null -D - "$WEB/demo")
check "/demo -> 302 a la landing en modo demo (DMO-001)" "$(printf '%s' "$RD" | head -1 | awk '{print $2}') $(printf '%s' "$RD" | grep -ic '^location:.*modo=demo')" "302 1"
check "/demo sin caché (DMO-001)" "$(printf '%s' "$RD" | grep -ic '^cache-control:.*no-store')" "1"
check "estado de la demo (DMO-037)" "$(curl -s "$B/v1/public/demo-status" | jq_ "d['data']['enabled']")" "True"
check "visita de campaña -> 204 (DMO-030)" "$(curl -s -o /dev/null -w '%{http_code}' -H "$J" -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)' "$B/v1/public/visits" -d '{"campaign":"prueba_interna","source":"qr","medium":"folleto"}')" "204"
check "campaña no admitida -> PH-VAL-001" "$(curl -s -H "$J" "$B/v1/public/visits" -d '{"campaign":"otra","source":"qr","medium":"folleto"}' | code)" "PH-VAL-001"
check "campo no declarado -> PH-VAL-001" "$(curl -s -H "$J" "$B/v1/public/visits" -d '{"campaign":"prueba_interna","source":"qr","medium":"folleto","ip":"1.2.3.4"}' | code)" "PH-VAL-001"
check "métricas del QR para la plataforma (DMO-035)" "$(curl -s -b "$T/a" "$B/v1/admin/campaign-metrics?campaign=prueba_interna" | jq_ "d['data']['visitas'] >= 1 and d['data']['por_dispositivo']['MOVIL'] >= 1 and len(d['data']['por_hora']) == 24")" "True"
check "/app -> 302 a la página de instalación" "$(curl -s -o /dev/null -D - "$WEB/app" | grep -i '^location:' | grep -c 'utm_campaign=instalar_app#/instalar')" "1"
check "instalación de la app -> 204" "$(curl -s -o /dev/null -w '%{http_code}' -H "$J" -A 'Mozilla/5.0 (Linux; Android 15)' "$B/v1/public/visits" -d '{"campaign":"prueba_interna","source":"web","medium":"instalacion"}')" "204"
check "las instalaciones se cuentan aparte de las visitas" "$(curl -s -b "$T/a" "$B/v1/admin/campaign-metrics?campaign=prueba_interna" | jq_ "d['data']['instalaciones']['total'] >= 1 and d['data']['instalaciones']['por_dispositivo']['MOVIL'] >= 1")" "True"
check "la gestión no ve las métricas -> PH-PERM-001" "$(curl -s -b "$T/h" "$B/v1/admin/campaign-metrics" | code)" "PH-PERM-001"
if [ -n "$DP" ]; then
  P='X-PymeHub-Prueba: 1'
  curl -s -c "$T/d1" -H "$J" -H "$P" "$B/v1/auth/login" -d "{\"email\":\"gerente@pymehub.demo\",\"password\":\"$DP\"}" > "$T/d1.json"
  check "login demo de gerente@ (DMO-023)" "$(jq_ "d['data']['usuario']['perfil']+' '+str(d['data']['demo'])" < "$T/d1.json")" "GERENTE True"
  CD=$(jq_ "d['data']['csrf_token']" < "$T/d1.json")
  curl -s -c "$T/d2" -H "$J" -H "$P" "$B/v1/auth/login" -d "{\"email\":\"gerente@pymehub.demo\",\"password\":\"$DP\"}" > /dev/null
  check "sesiones concurrentes con el mismo usuario demo (DMO-026)" "$(curl -s -b "$T/d1" "$B/v1/auth/me" | jq_ "d['data']['usuario']['perfil']") $(curl -s -b "$T/d2" "$B/v1/auth/me" | jq_ "d['data']['usuario']['perfil']")" "GERENTE GERENTE"
  check "demo: crear una cuenta de RR. HH. -> PH-DEMO-001 (DMO-024)" "$(curl -s -b "$T/d1" -H "$J" -H "X-CSRF-Token: $CD" "$B/v1/users" -d '{"nombre":"Prueba","email":"x@x.es"}' | code)" "PH-DEMO-001"
  DE=$(curl -s -b "$T/d1" "$B/v1/employees?per_page=1" | jq_ "d['data'][0]['id']")
  check "demo: dar de baja a un empleado -> PH-DEMO-001" "$(curl -s -b "$T/d1" -X DELETE -H "$J" -H "X-CSRF-Token: $CD" "$B/v1/employees/$DE" -d "{\"fecha_baja\":\"$HOY\",\"motivo_baja\":\"VOLUNTARIA\"}" | code)" "PH-DEMO-001"
  check "demo: alta con email -> PH-DEMO-001" "$(curl -s -b "$T/d1" -H "$J" -H "X-CSRF-Token: $CD" "$B/v1/employees" -d '{"nombre":"Leo","email":"leo@ejemplo.es","puesto":"OTRO","tipo_contrato":"ETT","turno":"TARDE","fecha_alta":"2024-01-15"}' | code)" "PH-DEMO-001"
  check "demo: exportar la empresa -> PH-DEMO-001" "$(curl -s -b "$T/d1" "$B/v1/company/export" | code)" "PH-DEMO-001"
  check "demo: contratar -> PH-DEMO-001" "$(curl -s -b "$T/d1" -H "$J" -H "X-CSRF-Token: $CD" "$B/v1/billing/checkout" -d '{"plan":"COMPLETO","ciclo":"MENSUAL","acepta_terminos":true}' | code)" "PH-DEMO-001"
  check "demo: comprar en Skills -> PH-DEMO-001" "$(curl -s -b "$T/d1" -H "$J" -H "X-CSRF-Token: $CD" "$B/v1/skills/purchases" -d '{"modulo_id":1,"acepta_condiciones":true}' | code)" "PH-DEMO-001"
  check "demo: catálogo de Skills con la licencia de Castilla" "$(curl -s -b "$T/d1" "$B/v1/skills/catalog" | jq_ "str(len(d['data']) >= 2 and any(c['licencia_empresa'] for c in d['data']))")" "True"
  check "demo: formador@ ve sus tres cursos y sus ventas" "$(curl -s -c "$T/df" -H "$J" -H "$P" "$B/v1/auth/login" -d "{\"email\":\"formador@pymehub.demo\",\"password\":\"$DP\"}" > /dev/null; curl -s -b "$T/df" "$B/v1/skills/my-courses" | jq_ "d['meta']['total']") $(curl -s -b "$T/df" "$B/v1/skills/sales" | jq_ "d['meta']['totales']['ventas']")" "3 2"
  check "demo: ve su vigencia y su historial de pagos" "$(curl -s -b "$T/d1" "$B/v1/billing/invoices" | jq_ "str(d['meta']['total'] >= 3)")" "True"
  check "demo: sí ve el riesgo de su empresa" "$(curl -s -b "$T/d1" "$B/v1/risk?level=ALTO" | jq_ "5 <= d['meta']['total'] <= 8")" "True"
  for i in 1 2 3 4 5; do curl -s -H "$J" -H "$P" "$B/v1/auth/login" -d '{"email":"gerente@pymehub.demo","password":"mala-contrasena"}' > /dev/null; done
  check "5 fallos no bloquean la cuenta demo (DMO-025)" "$(curl -s -H "$J" -H "$P" "$B/v1/auth/login" -d "{\"email\":\"gerente@pymehub.demo\",\"password\":\"$DP\"}" | jq_ "d['data']['usuario']['perfil']")" "GERENTE"
else
  echo "  (sin PASSWORD_DEMO: se saltan las comprobaciones de inicio de sesión demo)"
fi

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
