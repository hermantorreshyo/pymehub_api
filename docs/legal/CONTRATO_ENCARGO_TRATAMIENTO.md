# Contrato de encargo del tratamiento de datos personales · Pyme Hub

**Plantilla · versión 2026-10-17 · pendiente de revisión jurídica.** Requisitos `LEG-016` y `LEG-021`. Se firma con cada empresa cliente **antes de invitar a su plantilla**. Al firmarse, el administrador registra la fecha en la ficha de la empresa (`interlocutor.fecha_contrato_encargado`). Sin esa fecha, la API no permite invitar empleados (`PH-TENANT-003`).

> **Sociedad en constitución.** Los datos del encargado se rellenarán cuando la sociedad titular de Pyme Hub esté constituida. Son los mismos que se introducen en Administración → Parámetros → «Titular de la plataforma». Hasta entonces este documento no debe firmarse con clientes reales. Durante el TFM solo se usan datos ficticios (`LEG-009`).

---

## Reunidos

**De una parte, el RESPONSABLE DEL TRATAMIENTO:** [razón social de la empresa cliente], con NIF [·], domicilio en [·]. Le representa [nombre y cargo].

**De otra, el ENCARGADO DEL TRATAMIENTO:** [razón social del titular de Pyme Hub — pendiente de constitución], con NIF [·], domicilio en [·] e inscrita en [datos registrales]. Le representa [nombre y cargo]. Contacto de protección de datos: [correo; provisionalmente info@hyo.company].

Ambas partes se reconocen capacidad para obligarse y acuerdan este contrato conforme al artículo 28 del Reglamento (UE) 2016/679 (RGPD) y a la Ley Orgánica 3/2018 (LOPDGDD).

## 1. Objeto

El Encargado presta al Responsable el servicio Pyme Hub, una plataforma de bienestar, formación e inteligencia artificial para la gestión de personas. Para ello trata, por cuenta del Responsable, los datos personales de su plantilla que se describen en el anexo I. El contrato de servicio y los términos de contratación (`#/legal/terminos`) regulan el resto de la relación.

## 2. Duración

El contrato dura lo mismo que el contrato de servicio. Al terminar se aplica la cláusula 10.

## 3. Obligaciones del Encargado

El Encargado se obliga a:

1. Tratar los datos **solo para prestar el servicio** y siguiendo las instrucciones documentadas del Responsable, que son este contrato y la configuración que el Responsable hace en la plataforma. Si una instrucción le parece contraria a la normativa, avisará de inmediato.
2. No usar los datos para fines propios ni comunicarlos a terceros, salvo a los subencargados de la cláusula 5 o por obligación legal.
3. Garantizar que las personas autorizadas para tratar los datos se han comprometido a la **confidencialidad**.
4. Aplicar las **medidas de seguridad** del anexo II (art. 32 RGPD).
5. **Ayudar al Responsable a atender los derechos** de las personas interesadas (acceso, rectificación, supresión, oposición, limitación y portabilidad). La plataforma ofrece las herramientas: «Descargar mis datos» para cada persona, exportación completa de la empresa y supresión de los datos de una persona dada de baja.
6. **Notificar al Responsable, sin dilación indebida y en un máximo de 48 horas**, cualquier violación de la seguridad de los datos de la que tenga conocimiento. La notificación incluirá la información del art. 33.3 RGPD de que disponga.
7. Ayudar al Responsable en las evaluaciones de impacto y en las consultas previas, cuando proceda.
8. Poner a disposición del Responsable la información necesaria para demostrar el cumplimiento y permitir auditorías razonables, con un preaviso de 30 días.
9. Llevar el registro de actividades de tratamiento como encargado (art. 30.2 RGPD).

## 4. Obligaciones del Responsable

El Responsable se obliga a:

1. Informar a su plantilla del tratamiento antes de invitarla. La plataforma muestra además el aviso de privacidad en la activación de cada cuenta (`LEG-001`).
2. Garantizar que tiene una base jurídica para el tratamiento.
3. **No registrar datos de salud**: ni bajas médicas ni sus causas (`LEG-012`). Las incidencias se limitan a ausencias injustificadas, retrasos, siniestros, quejas y reconocimientos.
4. No adoptar decisiones sobre las personas basadas únicamente en la puntuación de riesgo de rotación ni en las recomendaciones de la IA, y mantener siempre la supervisión humana (`LEG-005`, art. 22 RGPD).
5. Respetar el carácter voluntario del check-in de bienestar: no exigirlo ni tener en cuenta quién responde (`LEG-013`).

## 5. Subencargados

El Responsable autoriza de forma general a los subencargados siguientes. El Encargado le avisará con 30 días de antelación de cualquier cambio, y el Responsable podrá oponerse y, en su caso, resolver el contrato.

| Subencargado | Servicio | Ubicación | Datos |
|---|---|---|---|
| Hostinger International Ltd. | Alojamiento del servidor (VPS) | [confirmar la ubicación del centro de datos; preferiblemente UE] | Todos los del anexo I, cifrados en tránsito |
| Stripe Payments Europe Ltd. | Cobro de la suscripción | Irlanda (UE) | Solo datos de facturación del Responsable, no de la plantilla |
| Mistral AI SAS | Asesor de formación con IA | Francia (UE) | **Ningún dato personal** (`LEG-010`): puesto, tramo de antigüedad, turno, preferencias y catálogo, sin nombre, empresa ni datos de salud |

Ningún subencargado transfiere datos fuera del Espacio Económico Europeo sin las garantías del capítulo V del RGPD.

## 6. Datos especialmente protegidos

La plataforma no está diseñada para tratar categorías especiales de datos (art. 9 RGPD). El check-in de bienestar recoge ánimo, energía y estrés de forma voluntaria y lo protege así:

- la nota libre se cifra y solo la lee la propia persona (`LEG-003`);
- la gestión solo ve medias de grupos de 5 o más personas (`LEG-002`);
- el check-in no interviene en el riesgo de rotación (`LEG-013`);
- los check-ins se conservan 12 meses (`LEG-008`).

## 7. Inteligencia artificial

- El **riesgo de rotación** es un motor de reglas documentado en la hoja técnica del modelo, con explicación por factor, registro de consultas y revisión trimestral de sesgos (`LEG-004`, `LEG-006`, `LEG-015`).
- El **asesor de formación** usa IA generativa de un tercero, identifica sus respuestas como generadas por IA (`LEG-011`) y no recibe datos personales.

## 8. Plazos de conservación dentro del servicio

| Dato | Conservación |
|---|---|
| Check-ins de bienestar | 12 meses |
| Registro de consultas al asesor | 12 meses |
| Datos de una persona dada de baja | Hasta que el Responsable los suprima o termine el contrato. Los indicadores de rotación conservan, sin identificar a nadie, fechas, motivo, puesto y equipo |
| Copias de seguridad | 14 días, en rotación |

## 9. Seguridad

Las medidas se detallan en el anexo II.

## 10. Fin del encargo: devolución y supresión

1. Al terminar el contrato, el Responsable puede **exportar todos sus datos** en formato estructurado (ZIP con CSV y JSON) desde la plataforma, en cualquier momento antes del borrado.
2. La cuenta queda en solo lectura y los datos se **borran definitivamente a los 30 días** de la baja, salvo otro plazo pactado (`LEG-017`). Las copias de seguridad se sobrescriben en los 14 días siguientes.
3. El Encargado solo conservará datos bloqueados cuando lo exija la ley, y durante el plazo de prescripción de las responsabilidades.

## 11. Responsabilidad

Cada parte responde de sus incumplimientos conforme al art. 82 RGPD. Si el Encargado trata los datos para otros fines o incumple este contrato, será considerado responsable del tratamiento respecto de ese tratamiento (art. 28.10 RGPD).

## 12. Ley y jurisdicción

Este contrato se rige por la legislación española. Para cualquier controversia, las partes se someten a los juzgados y tribunales de [ciudad].

En [ciudad], a [fecha].

| El Responsable | El Encargado |
|---|---|
| [firma] | [firma] |

---

## Anexo I · Descripción del tratamiento

- **Personas interesadas:** personas trabajadoras del Responsable (incluidas las de ETT si las registra) y cuentas de gestión (gerencia y RR. HH.).
- **Categorías de datos:**
  - identificativos: nombre, apellidos, correo y código interno;
  - laborales: puesto, equipo, turno, tipo de contrato, fechas de alta y baja, y motivo de la baja;
  - incidencias laborales (sin datos de salud);
  - formación: asignaciones, avance y resultados;
  - bienestar voluntario: ánimo, energía, estrés y nota cifrada;
  - preferencias de aprendizaje;
  - puntuación de riesgo de rotación con sus factores;
  - registros de acceso y de auditoría.
- **Operaciones:** recogida, registro, conservación, consulta, cálculo de indicadores agregados y de la puntuación de riesgo, exportación y supresión.
- **Finalidad:** prestar el servicio Pyme Hub de bienestar, formación y prevención de la rotación.

## Anexo II · Medidas de seguridad

- Cifrado en tránsito (HTTPS con TLS 1.2 o superior y HSTS).
- Contraseñas con Argon2id.
- Sesiones con cookie HttpOnly, Secure y SameSite, y protección CSRF.
- Notas del check-in cifradas en reposo (`sodium_crypto_secretbox`), con la clave fuera de la base de datos.
- Aislamiento estricto de los datos de cada empresa: el identificador de la empresa sale siempre de la sesión, nunca de la petición, y las claves foráneas son compuestas.
- Control de acceso por perfil y plan. Registro de auditoría de las consultas a la información sensible.
- Limitación de intentos de acceso y de peticiones.
- Copias de seguridad diarias con 14 días de rotación y prueba de restauración documentada.
- Monitorización de la disponibilidad y registro de errores sin datos personales.
- Nunca se registran contraseñas, tokens, contenido de check-ins ni prompts de IA.
- Mínimo privilegio en el servidor; actualizaciones de seguridad del sistema a cargo del administrador del VPS.
