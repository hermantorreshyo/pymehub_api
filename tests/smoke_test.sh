#!/usr/bin/env bash
# Prueba de humo de Pyme Hub API (fases 0-5).
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

echo "== Baja laboral"
check "motivo no válido -> PH-VAL-001" "$(send h DELETE "$CH" "/v1/employees/$EID" "{\"fecha_baja\":\"$HOY\",\"motivo_baja\":\"DESPIDO\"}" | code)" "PH-VAL-001"
check "baja con motivo y sin acceso" "$(send h DELETE "$CH" "/v1/employees/$EID" "{\"fecha_baja\":\"$HOY\",\"motivo_baja\":\"VOLUNTARIA\"}" | campos 'motivo_baja estado_acceso')" "VOLUNTARIA BAJA"
check "ficha de baja en solo lectura -> PH-VAL-001" "$(send h PATCH "$CH" "/v1/employees/$EID" '{"turno":"NOCHE"}' | code)" "PH-VAL-001"
check "la baja sale de la lista de altas" "$(total h /v1/employees)" "2"
check "y aparece en las bajas" "$(total h '/v1/employees?situacion=BAJA')" "1"

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
