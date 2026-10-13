# Colección de Postman · Pyme Hub API

1. En Postman, **Import** → `PymeHub_API.postman_collection.json` y uno de los entornos (`PymeHub_Local` o `PymeHub_Produccion`).
2. En el entorno, rellena `email` y `password` del usuario con el que vas a probar.
3. Ejecuta **01 · Autenticación → Login**: Postman guarda la cookie de sesión y el token CSRF (`{{csrf}}`), que se envía en `X-CSRF-Token` en todas las peticiones que modifican datos.
4. Las peticiones de alta guardan los identificadores (`empresa_id`, `equipo_id`, `empleado_id`…) para las siguientes.

Recorrido probado con Newman (v0.7.0):

| Paso | Usuario | Carpetas |
|---|---|---|
| 1 | Administrador de la plataforma | 00, 01, 02 (crea una empresa y guarda la invitación del gerente) |
| 2 | Sin sesión | 14 (acepta la invitación del gerente) |
| 3 | Gerente recién activado | 01, 03, 04, 05, 06, 07, 15 |
| 4 | `conductor@pymehub.demo` (datos de demo) | 01, 08, 11, 12 |
| 5 | `gerente@pymehub.demo` (datos de demo) | 01, 09, 10 |
| 6 | `rrhh@pymehub.demo` (datos de demo, plan Completo) | 01, 13 (9 peticiones, todas correctas). «Recalcular ahora» admite una llamada cada 10 minutos por empresa |
| 7 | Sin sesión y después `admin@pymehub.demo` | 16 (demo comercial: estado, visita de prueba y métricas del QR) |

Con `gerente2@pymehub.demo` (plan Entrada), la carpeta 13 responde `PH-PLAN-001` salvo el panel de indicadores, que es común a los dos planes.

La colección crece con cada avance, a la par que `docs/openapi.yaml`.
