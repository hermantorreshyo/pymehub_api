# Manual 04 · Diagnóstico del despliegue

**Versión:** API 0.11.0 · 18/10/2026 · Requisito `RNF-068`.

El diagnóstico comprueba que Pyme Hub está completo y sano después de un despliegue o cuando algo no va bien. **Solo mira: no cambia nada**, y nunca muestra contraseñas ni claves.

---

## 1. Para cualquier persona (sin conocimientos técnicos)

1. Abre **https://pymehub.hermantorres.com** y entra con una **cuenta de la plataforma** (administración).
2. En el menú, pulsa **Diagnóstico**. Las comprobaciones tardan unos segundos.
3. Arriba aparece el resultado general:

| Color | Qué significa |
|---|---|
| Verde · **Todo está bien** | No hay que hacer nada. |
| Ámbar · **Funciona, pero hay puntos que revisar** | La aplicación funciona; conviene revisar lo marcado cuando se pueda. |
| Rojo · **Hay problemas que resolver** | Algo falla o falta. Avisa al responsable técnico. |

4. Marca **«Ver solo lo que hay que revisar»** para ver únicamente lo que necesita atención. Cada punto tiene uno de estos estados, siempre con texto e icono:
   - **Correcto**: está bien.
   - **Revisar**: funciona, pero conviene mirarlo.
   - **Falla**: algo no funciona o falta.
   - **Informativo**: un dato o un pendiente que no depende del software, como la constitución de la sociedad o la respuesta de la gestoría.

   Debajo de cada punto, «Qué hacer» indica la solución.
5. Para pedir ayuda, pulsa **«Copiar el informe»** y pégalo en un correo, o **«Descargar»** para adjuntar el archivo `.txt`.

Si la página no carga o no puedes entrar, el problema es anterior al diagnóstico (servidor caído, DNS o certificado). En ese caso, avisa directamente al responsable técnico.

---

## 2. Para el responsable técnico (terminal)

```bash
API=/var/www/hermantorres/eude/pymehub/pymehub_api
sudo -u www-data php $API/cli/diagnostico.php                     # informe completo
sudo -u www-data php $API/cli/diagnostico.php --solo-problemas    # solo avisos y errores
sudo -u www-data php $API/cli/diagnostico.php --sin-red           # sin comprobar las URL ni Stripe
```

- Código de salida: `0` sin errores (puede haber avisos) y `1` con errores. Sirve para automatizarlo o para usarlo tras cada despliegue.
- La pantalla y la terminal usan las mismas comprobaciones (`Services/DiagnosticoService`). También están en la API (`GET /v1/admin/diagnostics`, solo para la plataforma, con un límite de 20 por hora).
- Desde la terminal, `/etc/cron.d/pymehub` se lee igual que desde la web: el archivo tiene permisos 644.

---

## 3. Qué se comprueba

| Grupo | Comprobaciones |
|---|---|
| Versión y código | Versión de la API; migraciones aplicadas frente a las del código; que la web esté en la misma versión (lee `sw.js`) |
| Servidor | PHP 8.4 y extensiones; curl; motor y versión de la base de datos; espacio en disco; permisos de `storage`; instalador bloqueado; permisos de `config.php` |
| Copias de seguridad | Antigüedad de la última copia; copias guardadas; último informe de `restore_test.sh` y su resultado |
| Tareas programadas | Que `/etc/cron.d/pymehub` tenga las ocho tareas (con la línea exacta si falta alguna) y que se estén ejecutando |
| Seguridad | `production`; HTTPS; cookie segura; clave de cifrado; CORS; contraseña de la cuenta de pruebas; cabeceras de seguridad de la API y de la web; huella del servidor |
| Operación | Mantenimiento; errores 5xx en 24 h; recordatorio del monitor externo |
| Demo comercial | Demo habilitada; contraseña; los cinco usuarios demo; reinicio nocturno; redirecciones `/demo` y `/app` |
| Parámetros y legal | Titular (sociedad en constitución); precios, IVA y descuento; versión legal; IVA de la formación de Skills |
| Pagos (Stripe) | Modo (prueba o real); secreto del webhook; conexión con Stripe; últimos webhooks; formadores con cobro activo |
| Asesor con IA | Modo, clave configurada y respuestas en caché |
| Datos | Empresas; suscripciones vencidas; cursos de Skills por revisar |

---

## 4. Resultado esperado hoy en producción

Mientras sigan pendientes las tareas acordadas, es normal ver estos puntos sin que haya ningún problema:

- **Informativo**:
  - Stripe sin configurar;
  - sociedad en constitución;
  - revisión jurídica de los textos;
  - IVA de la formación pendiente de la gestoría;
  - monitor externo.
- **Revisar**, en las cabeceras de seguridad o la huella del servidor, hasta cerrar la fase 10 (nota A).

Todo lo demás debería salir **Correcto**.
