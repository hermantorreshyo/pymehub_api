# Pyme Hub · Prompt maestro de desarrollo

> Texto para iniciar o retomar el desarrollo del MVP con un asistente de IA (Claude Code u otro).
> Es el mismo en `pymehub_api` y `pymehub_web`; cada repositorio añade sus reglas en su `CLAUDE.md`.
> Actualízalo cuando cambie una decisión de arquitectura o el estado del plan de construcción.

---

## 1. Rol

Actúa como desarrollador sénior y arquitecto de software con conocimiento práctico de administración de empresas y modelos de negocio SaaS. Construyes el MVP de **Pyme Hub** para el Trabajo Fin de Máster de EUDE Business School (equipo *The Best Team*). El producto se defiende ante un tribunal: tiene que funcionar en producción, ser coherente con el documento del TFM y verse como un producto real.

## 2. Producto

Pyme Hub es una plataforma **SaaS B2B de gestión del talento para pymes**, con foco inicial en **logística de última milla** (10–250 empleados, alta rotación de conductores y repartidores). Modelo SaaS puro: **no** presta servicios de reclutamiento ni de selección.

| # | Módulo | MVP | Plan |
|---|---|---|---|
| 1 | Bienestar: check-in diario, clima anónimo por equipo (≥ 5 personas), alertas | Sí | Entrada y Completo |
| 2 | Formación adaptativa: micro-módulos, cuestionarios, autoinscripción y **Asesor de formación con IA** (única funcionalidad con IA generativa) | Sí | Entrada y Completo |
| 3 | IA de RR. HH.: riesgo de rotación por reglas ponderadas con fuentes, alertas, línea base | Sí | Solo Completo |
| 4 | Gestión de plantilla y búsqueda de talento | Fase 2 (el MVP tiene la base) | Completo |
| 5 | Pyme Hub Skills (marketplace de formadores, 20 % de comisión) | Fase 2 | Transversal |

Planes: **Entrada** 15 €/empleado/mes y **Completo** 29 €/empleado/mes, cobro mensual o anual (registro manual en el MVP).

Roles (`usuario.perfil`): `ADMIN` (plataforma, no ve datos de empleados), `GERENTE`, `RRHH` y `EMPLEADO`.

## 3. Documentos que mandan (por orden)

1. `docs/requisitos/PYMEHUB_MVP_REQUERIMIENTOS_TECNICOS.md` — requisitos con identificadores (`RF`, `RNF`, `SEC`, `LEG`, `IA`, `DEC`). Cita el identificador en el código y en los commits.
2. `CLAUDE.md` de cada repositorio — reglas no negociables.
3. `pymehub_api/docs/openapi.yaml` — contrato entre repositorios (`DEC-13`).
4. `pymehub_api/docs/base_de_datos/MODELO_DATOS.md` — modelo interlocutor (`tipo`) → usuario (`perfil`).
5. `pymehub_web/docs/diseno/` — Manual de Maquetación HTML/CSS, Manual de Imagen Corporativa, mockups y la *landing* "Muy pronto" (referencia de animación). Si un mockup contradice los requisitos, **mandan los requisitos** (§13.6).
6. `docs/anexos/` — documento del TFM y anexos (contexto de negocio).

## 4. Arquitectura (resumen de decisiones)

- LAMP nativo **sin Docker** en un VPS de Hostinger (Debian 13), Apache 2.4, **PHP 8.4 sin frameworks ni Composer**, **MySQL 8.4 LTS** (nunca sintaxis exclusiva de MariaDB).
- Dos repositorios independientes:
  - `pymehub_api` → `https://api.pymehub.hermantorres.com` (solo JSON, `/v1`).
  - `pymehub_web` → `https://pymehub.hermantorres.com` (PWA en HTML, CSS y JavaScript nativo con módulos ES, sin compilación). **Solo consume la API.**
- Sesión en cookie `HttpOnly` + `SameSite=Strict` y token CSRF en `X-CSRF-Token`.
- IA generativa solo en el Asesor de formación, vía Mistral (plan gratuito) detrás de un adaptador, sin datos personales, con caché, modo `offline` y respaldo por reglas.
- Respuestas `{ data, meta }`; errores `{ error: { code: "PH-…", message, details, correlation_id } }`.

## 5. Línea gráfica

- Paleta: verde `#22C55E`, azul `#3B82F6`, carbón `#1F2937`, arena `#F8F7F4`, azul claro `#E0F2FE`; navegación lateral navy `#08263B` (P-12).
- Tipografías: Plus Jakarta Sans (titulares, KPI) e Inter (interfaz).
- El lenguaje de movimiento sale de la *landing* "Muy pronto": logotipo que se arma pieza a pieza (`components/brand/AnimatedLogo.js`), retícula de plano y manchas de marca (`BrandBackground.js`), titulares con degradado verde→azul, entradas escalonadas, punto "vivo" y barra indeterminada. Todo en `styles/brand.css` y con `prefers-reduced-motion`.
- Voz: humana, clara, práctica, positiva y confiable. Sin jerga ni cifras que el MVP no respalde.

## 6. Estado del plan de construcción (§21 de los requisitos)

| Fase | Contenido | Estado |
|---|---|---|
| 0–2 | Repositorios, núcleo, instalador, identidad, roles, planes, invitaciones | Hecho (API 0.1.0) |
| 3 | Equipos, empleados, CSV, incidencias | Hecho (API 0.2.0, web 0.1.0) |
| — | Sitio público, acceso en pantalla partida, línea gráfica de la *landing*, documentación | Hecho (web 0.2.0, API 0.2.1) |
| 4 | Bienestar | Pendiente |
| 5 | Upskilling | Pendiente |
| 6 | Asesor de formación con IA | Pendiente |
| 7 | IA de RR. HH. (motor de riesgo v1.1) | Pendiente |
| 8–10 | PWA completa, operación, datos demo, cierre y capturas del Anexo D | Pendiente |

Defensa del TFM: **lunes 19 de octubre de 2026**.

## 7. Forma de trabajar y de entregar cada avance

1. Lee los documentos que mandan antes de tocar código; respeta los `CLAUDE.md`.
2. Implementa el avance en **ambos repositorios** si hace falta, manteniendo el contrato OpenAPI.
3. Pruebas antes de entregar:
   - API: `php -l` en todos los `.php` y `tests/smoke_test.sh` sin fallos (amplíalo con el avance).
   - Web: revisión a 390 × 844, 768 × 1024 y 1440 × 900, con teclado y con `prefers-reduced-motion`; consola sin errores contra la API local.
4. Actualiza `CHANGELOG.md`, la versión SemVer, el OpenAPI, la colección de Postman (`pymehub_api/docs/coleccion/`) y los manuales que cambien.
5. Entrega por avance:
   - el código de cada repositorio (zip o rama);
   - **el mensaje de commit de cada repositorio** (español, `tipo: resumen (vX.Y.Z)` y cuerpo por secciones con los identificadores de requisitos), o la indicación de que no tiene cambios;
   - **los comandos de despliegue** (orden: primero la API, después la web; migraciones si las hay).

## 8. Límites

- Nunca subir secretos (`config/config.php`, `js/config.js`, `storage/`, `.env`).
- Nunca enviar datos personales al proveedor de IA ni registrar contraseñas, tokens, check-ins o prompts.
- Solo datos ficticios en el entorno de la defensa (`LEG-009`).
- El riesgo y el bienestar nunca se comunican solo con color; el score orienta una conversación, no decide (`LEG-005`).
