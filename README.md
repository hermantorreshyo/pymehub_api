# Pyme Hub · API

Backend tipo API de **Pyme Hub**, la plataforma SaaS de bienestar, formación y retención del talento para PYMES (TFM · EUDE Business School · The Best Team).

Esta API es el único punto de acceso a los datos: el frontend ([`pymehub_web`](https://github.com/hermantorreshyo/pymehub_web)) solo la consume por HTTPS.

## Stack

PHP 8.4 sin frameworks ni Composer · MySQL 8.4 LTS · Apache 2.4 · sin Docker.

## Estructura

```
public/        Única carpeta expuesta (front controller)
src/           Núcleo, middlewares, controladores, servicios y repositorios
migrations/    Esquema MySQL versionado
install/       Asistente de instalación (/install, se bloquea tras usarse)
cli/           Migraciones y copias de seguridad
config/        config.example.php (el real lo genera el instalador y no se versiona)
storage/       Logs, sesiones, subidas, copias (no se versiona)
docs/          Manuales, modelo de datos y contrato OpenAPI
tests/         Prueba de humo
```

## Documentación

| Documento | Contenido |
|---|---|
| [`docs/manuales/01_MANUAL_DESPLIEGUE.md`](docs/manuales/01_MANUAL_DESPLIEGUE.md) | Despliegue en el VPS, HTTPS, copias, monitorización y actualizaciones |
| [`docs/base_de_datos/MODELO_DATOS.md`](docs/base_de_datos/MODELO_DATOS.md) | Modelo interlocutor/usuario y diagrama |
| [`docs/openapi.yaml`](docs/openapi.yaml) | Contrato con el frontend |

## Desarrollo local

```bash
# MySQL 8.4 local con una base vacía "pymehub"
php -S 127.0.0.1:8080 -t public public/index.php
# Abre http://127.0.0.1:8080/install (cookie HTTPS: "No" solo en local)
tests/smoke_test.sh http://127.0.0.1:8080 http://127.0.0.1:5500 admin@local.test 'contraseña'
```

## Estado

Versión **0.2.0**: núcleo, seguridad, instalación, empresas, cuentas de gestión e invitaciones (fases 1 y 2) y organización: equipos, empleados, importación CSV e incidencias (fase 3). El plan de construcción completo está en el documento de requisitos técnicos del MVP (§21).
