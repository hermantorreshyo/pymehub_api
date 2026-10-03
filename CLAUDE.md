# Pyme Hub API · instrucciones para Claude Code

Backend tipo API del MVP de Pyme Hub (TFM EUDE). El frontend es otro repositorio (`pymehub_web`) que solo consume esta API.

## Documentos que mandan
- `docs/requisitos/PYMEHUB_MVP_REQUERIMIENTOS_TECNICOS.md`: requisitos con identificadores (RF, RNF, SEC, LEG, IA, DEC). Cita el identificador en código y commits cuando implementes uno.
- `docs/base_de_datos/MODELO_DATOS.md`: modelo de datos.
- `docs/openapi.yaml`: contrato con el frontend. Actualízalo con cada endpoint.
- `docs/manuales/`: manuales. El 01 es el de despliegue; actualízalo si cambia algo del despliegue.

## Reglas no negociables
- PHP 8.4 sin frameworks ni Composer. MySQL 8.4 LTS (nunca sintaxis exclusiva de MariaDB). Apache. Sin Docker.
- Todos los actores en `interlocutor` (campo `tipo`); las personas en `usuario` (campo `perfil`). Un interlocutor tiene N usuarios. No crear tablas de actores paralelas.
- Tablas y columnas en español, minúsculas y singular. Enumerados en mayúsculas con `CHECK` o catálogo.
- Todo SQL en `src/Repositories` o servicios, siempre con PDO preparado. Acumulados actualizados de forma atómica en SQL.
- `interlocutor_id` siempre desde `Context`, nunca desde la petición. FK compuestas `(usuario_id, interlocutor_id)` en tablas de negocio.
- Toda ruta que modifica datos lleva `Auth::csrf()`. Perfiles con `Auth::require(...)` y planes con `Auth::plan(...)`.
- Errores con `ApiException` y códigos `PH-*` (tabla §16 de los requisitos). Entrada validada con `Validator` (rechaza campos no declarados).
- Nunca registrar contraseñas, tokens, contenido de check-ins ni prompts de IA. Nunca enviar datos personales al proveedor de IA.
- Esquema: cada cambio es una migración nueva `migrations/NNNN_descripcion.sql`; nunca editar una migración ya publicada.
- Secretos fuera del repositorio (`config/config.php` y `storage/` están en `.gitignore`).

## Antes de cada commit
1. `find . -name "*.php" -exec php -l {} \;` sin errores.
2. `tests/smoke_test.sh` en local: todas correctas. Amplíala con las comprobaciones del avance.
3. `CHANGELOG.md` y, si aplica, `docs/openapi.yaml` y manuales actualizados.

## Mensajes de commit
En español, formato convencional: `tipo: resumen (vX.Y.Z)` (feat, fix, docs, refactor, test, chore) y un cuerpo por secciones (Base de datos, Núcleo, Seguridad, Endpoints, Operación, Documentación) con los identificadores de requisitos implementados. Al terminar un avance, entrega también el mensaje de commit de `pymehub_web`, o indica que no tiene cambios.
